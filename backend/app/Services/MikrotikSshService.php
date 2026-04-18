<?php

namespace App\Services;

use App\Models\Router;
use App\Services\PasswordEncryptionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

class MikrotikSshService
{
    protected static array $connectionPool = [];
    protected static array $circuitState = [];
    protected static array $rateLimiter = [];

    protected ?SSH2 $ssh = null;
    protected ?SFTP $sftp = null;
    protected ?Router $router = null;

    protected int $timeout = 20;
    protected int $maxRetries = 3;
    protected int $backoffBaseMs = 300;

    protected int $circuitThreshold = 5;
    protected int $circuitCooldown = 60; // seconds
    protected int $rateLimitMs = 150;

    protected bool $connected = false;

    public function __construct(?Router $router = null)
    {
        if ($router !== null) {
            $this->router = $router;
        }
    }

    /* =====================================================
     * Connection Management (Pool + Circuit Breaker)
     * ===================================================== */
    public function connect(): void
    {
        $this->checkCircuit();
        $this->applyRateLimit();

        $poolKey = $this->router->id;

        if (isset(self::$connectionPool[$poolKey])) {
            $pooled = self::$connectionPool[$poolKey];

            if ($pooled['ssh']->isConnected()) {
                $this->ssh = $pooled['ssh'];
                $this->sftp = $pooled['sftp'];
                $this->connected = true;
                return;
            }
        }

        $this->retry(function () use ($poolKey) {

            $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];
            $port = $this->resolveSshPort();
            $ssh  = new SSH2($host, $port);
            $ssh->setTimeout($this->timeout);

            $username = $this->router->username;
            $password = PasswordEncryptionService::safeDecrypt($this->router);

            $loginSuccess = false;

            // Try SSH key first (encrypted blob stored in router->ssh_key)
            if (!empty($this->router->ssh_key)) {
                try {
                    $rawKey = Crypt::decrypt($this->router->ssh_key);
                    $key = PublicKeyLoader::load($rawKey);
                    $loginSuccess = $ssh->login($username, $key);
                } catch (Throwable $e) {
                    $this->log('warning', 'SSH key login failed, fallback to password', ['error' => $e->getMessage()]);
                }
            }

            // Global key fallback
            if (!$loginSuccess) {
                $globalKeyPath = (string) env('MIKROTIK_SSH_PRIVATE_KEY_PATH', '');
                if ($globalKeyPath !== '' && is_file($globalKeyPath)) {
                    try {
                        $key = PublicKeyLoader::load(file_get_contents($globalKeyPath));
                        $loginSuccess = $ssh->login($username, $key);
                    } catch (Throwable $e) {
                        $this->log('warning', 'Global SSH key login failed', ['error' => $e->getMessage()]);
                    }
                }
            }

            // Password fallback
            if (!$loginSuccess && $password !== null) {
                $loginSuccess = $ssh->login($username, $password);
            }

            if (!$loginSuccess) {
                $this->recordFailure();
                throw new \RuntimeException("SSH authentication failed");
            }

            $sftp = new SFTP($host, $port);
            $sftpLoggedIn = false;

            if (!empty($this->router->ssh_key)) {
                try {
                    $rawKey = Crypt::decrypt($this->router->ssh_key);
                    $key = PublicKeyLoader::load($rawKey);
                    $sftpLoggedIn = $sftp->login($username, $key);
                } catch (Throwable $e) {
                    $this->log('warning', 'SFTP key login failed', ['error' => $e->getMessage()]);
                }
            }

            if (!$sftpLoggedIn && $password !== null) {
                $sftpLoggedIn = $sftp->login($username, $password);
            }

            if (!$sftpLoggedIn) {
                // SFTP login failure is non-fatal for command-only operations
                $this->log('warning', 'SFTP login failed (file upload will not work)');
            }

            self::$connectionPool[$poolKey] = [
                'ssh' => $ssh,
                'sftp' => $sftp,
            ];

            $this->ssh = $ssh;
            $this->sftp = $sftp;
            $this->connected = true;

            $this->resetCircuit();
            $this->log('info', 'Connected (pooled)');
        });
    }

    /* =====================================================
     * Execution
     * ===================================================== */
    public function exec(string $command, int $timeout = null): string
    {
        $this->connect();

        $this->ssh->setTimeout($timeout ?? $this->timeout);
        $start = microtime(true);

        $output = $this->ssh->exec($command);
        $stderr = $this->ssh->getStdError();

        $duration = round((microtime(true) - $start) * 1000);

        if ($stderr) {
            $this->recordFailure();
            $this->log('error', 'Command failed', [
                'command' => $command,
                'stderr' => $stderr,
                'duration_ms' => $duration
            ]);
            throw new \RuntimeException($stderr);
        }

        $this->log('debug', 'Command OK', [
            'command' => $command,
            'duration_ms' => $duration
        ]);

        return $output ?? '';
    }

    public function execBatch(array $commands): array
    {
        $results = [];

        foreach ($commands as $cmd) {
            $results[$cmd] = $this->exec($cmd);
        }

        return $results;
    }

    /**
     * Idempotent execution
     */
    public function execIfNotExists(string $checkCommand, string $applyCommand): string
    {
        $check = $this->exec($checkCommand);

        if (trim($check) !== '') {
            $this->log('debug', 'Skipped (already exists)', [
                'apply' => $applyCommand
            ]);
            return $check;
        }

        return $this->exec($applyCommand);
    }

    /* =====================================================
     * File Operations
     * ===================================================== */
    public function upload(string $localPath, string $remotePath): void
    {
        $this->connect();

        if (!file_exists($localPath)) {
            throw new \InvalidArgumentException("File not found: {$localPath}");
        }

        $tmp = $remotePath . '.tmp';

        if (!$this->sftp->put($tmp, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            $this->recordFailure();
            throw new \RuntimeException("Upload failed");
        }

        $this->exec("/file remove {$remotePath}");
        $this->exec("/file set [find name=\"{$tmp}\"] name=\"{$remotePath}\"");

        $this->log('info', 'Uploaded', [
            'file' => $remotePath,
            'size' => filesize($localPath)
        ]);
    }

    public function executeScript(string $localRscPath): string
    {
        $remote = 'import_' . uniqid() . '.rsc';

        $this->upload($localRscPath, $remote);
        $output = $this->exec("/import file-name={$remote}", 300);

        try {
            $this->exec("/file remove {$remote}");
        } catch (Throwable) {}

        return $output;
    }

    /* =====================================================
     * Fleet Protection
     * ===================================================== */
    protected function applyRateLimit(): void
    {
        $id = $this->router->id;
        $now = microtime(true) * 1000;

        if (isset(self::$rateLimiter[$id])) {
            $elapsed = $now - self::$rateLimiter[$id];
            if ($elapsed < $this->rateLimitMs) {
                usleep(($this->rateLimitMs - $elapsed) * 1000);
            }
        }

        self::$rateLimiter[$id] = microtime(true) * 1000;
    }

    protected function checkCircuit(): void
    {
        $id = $this->router->id;

        if (!isset(self::$circuitState[$id])) {
            return;
        }

        $state = self::$circuitState[$id];

        if ($state['open'] && (time() - $state['last_failure']) < $this->circuitCooldown) {
            throw new \RuntimeException("Circuit open for router {$id}");
        }
    }

    protected function recordFailure(): void
    {
        $id = $this->router->id;

        if (!isset(self::$circuitState[$id])) {
            self::$circuitState[$id] = [
                'failures' => 0,
                'open' => false,
                'last_failure' => time()
            ];
        }

        self::$circuitState[$id]['failures']++;
        self::$circuitState[$id]['last_failure'] = time();

        if (self::$circuitState[$id]['failures'] >= $this->circuitThreshold) {
            self::$circuitState[$id]['open'] = true;
            $this->log('critical', 'Circuit opened');
        }
    }

    protected function resetCircuit(): void
    {
        self::$circuitState[$this->router->id] = [
            'failures' => 0,
            'open' => false,
            'last_failure' => null
        ];
    }

    /* =====================================================
     * Retry
     * ===================================================== */
    protected function retry(callable $callback)
    {
        $attempt = 0;

        start:

        try {
            return $callback();
        } catch (Throwable $e) {

            $attempt++;

            if ($attempt >= $this->maxRetries) {
                $this->log('critical', 'Max retries reached', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            $delay = $this->backoffBaseMs * (2 ** ($attempt - 1));
            usleep($delay * 1000);

            $this->disconnect();
            goto start;
        }
    }

    /* =====================================================
     * Utilities
     * ===================================================== */
    public function ping(): bool
    {
        try {
            $out = $this->exec('/system resource print');
            return str_contains($out, 'uptime');
        } catch (Throwable) {
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
        }

        if ($this->sftp) {
            $this->sftp->disconnect();
        }

        $this->connected = false;
    }

    protected function decrypt(?string $value): ?string
    {
        if (!$value) return null;

        try {
            return Crypt::decrypt($value) ?? $value;
        } catch (Throwable) {
            return $value;
        }
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $host = $this->router->vpn_ip ?? $this->router->ip_address;
        $context = array_merge($context, [
            'router_id' => $this->router->id,
            'host' => $host ? explode('/', $host)[0] : 'unknown',
        ]);

        Log::{$level}("[MikroTik SSH] {$message}", $context);
    }

    /* =====================================================
     * High-level API used by provisioning and controllers
     * ===================================================== */

    protected function resolveSshPort(): int
    {
        $routerPort = $this->router->ssh_port ?? null;
        if ($routerPort !== null && (int) $routerPort > 0) {
            return (int) $routerPort;
        }

        $legacyPort = $this->router->port ?? null;
        if ($legacyPort !== null) {
            $legacyPort = (int) $legacyPort;
            if ($legacyPort > 0 && $legacyPort !== 8728) {
                return $legacyPort;
            }
        }

        $envPort = (int) env('MIKROTIK_SSH_PORT', 22);
        return $envPort > 0 ? $envPort : 22;
    }

    /**
     * Execute a single command on the router (used by SnmpConfigurationService etc.)
     */
    public function executeCommand(Router $router, string $command): string
    {
        $this->router = $router;
        return $this->exec($command);
    }

    /**
     * Fetch router interfaces for provisioning.
     * Returns ['interfaces' => [...], 'board_name' => ..., 'version' => ..., 'uptime' => ..., 'identity' => ...]
     */
    public function fetchInterfaces(Router $router, bool $filterConfigurable = false): array
    {
        $this->router = $router;

        $interfaceOutput = $this->exec('/interface print detail without-paging');
        $resourceOutput  = $this->exec('/system resource print');
        $identityOutput  = $this->exec('/system identity print');

        $this->disconnect();

        $interfaces = $this->parseInterfaces($interfaceOutput);

        if ($filterConfigurable) {
            $interfaces = array_values(array_filter($interfaces, fn($i) => $this->isConfigurableInterface($i)));
        }

        $systemInfo = $this->parseSystemInfo($resourceOutput, $identityOutput);

        return [
            'interfaces'  => array_values($interfaces),
            'board_name'  => $systemInfo['board_name'],
            'version'     => $systemInfo['version'],
            'uptime'      => $systemInfo['uptime'],
            'identity'    => $systemInfo['identity'],
        ];
    }

    /**
     * Fetch live router metrics for monitoring / details.
     */
    public function fetchLiveData(Router $router, bool $includeInterfaces = false): array
    {
        $this->router = $router;

        $resourceOutput         = $this->exec('/system resource print');
        $identityOutput         = $this->exec('/system identity print');
        $interfacesCountOutput  = $this->exec('/interface print count-only');
        $hotspotActiveOutput    = $this->exec('/ip hotspot active print count-only');
        $pppoeActiveOutput      = $this->exec('/ppp active print count-only');
        $dhcpLeasesOutput       = $this->exec('/ip dhcp-server lease print count-only');

        $interfaces = null;
        if ($includeInterfaces) {
            $interfaceOutput = $this->exec('/interface print detail without-paging');
            $interfaces = $this->parseInterfaces($interfaceOutput);
        }

        $this->disconnect();

        $systemInfo = $this->parseSystemInfo($resourceOutput, $identityOutput);

        $interfacesCount = (int) trim($interfacesCountOutput ?? '0');
        $hotspotActive   = (int) preg_replace('/[^0-9]/', '', (string) $hotspotActiveOutput);
        $pppoeActive     = (int) preg_replace('/[^0-9]/', '', (string) $pppoeActiveOutput);
        $dhcpLeases      = (int) preg_replace('/[^0-9]/', '', (string) $dhcpLeasesOutput);

        return [
            'status'           => 'online',
            'board_name'       => $systemInfo['board_name'],
            'version'          => $systemInfo['version'],
            'uptime'           => $systemInfo['uptime'],
            'identity'         => $systemInfo['identity'],
            'cpu_load'         => $systemInfo['cpu_load'],
            'free_memory'      => $systemInfo['free_memory'],
            'total_memory'     => $systemInfo['total_memory'],
            'free_hdd_space'   => $systemInfo['free_hdd_space'],
            'total_hdd_space'  => $systemInfo['total_hdd_space'],
            'interfaces_count' => $interfacesCount,
            'interface_count'  => $interfacesCount,
            'hotspot_active'   => $hotspotActive,
            'pppoe_active'     => $pppoeActive,
            'active_connections' => $hotspotActive + $pppoeActive,
            'dhcp_leases'      => $dhcpLeases,
            'interfaces'       => is_array($interfaces) ? array_values($interfaces) : [],
            'last_updated'     => now()->toDateTimeString(),
        ];
    }

    /* =====================================================
     * Parsing helpers
     * ===================================================== */

    private function parseInterfaces(string $output): array
    {
        $interfaces = [];
        $lines = explode("\n", $output);
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^\d+\s+R?\s+name="([^"]+)"/', $line, $m)) {
                if ($current) {
                    $interfaces[] = $current;
                }
                $current = [
                    'name'    => $m[1],
                    'type'    => 'ether',
                    'running' => str_contains($line, ' R ') ? 'true' : 'false',
                    'mtu'     => '1500',
                    'comment' => '',
                ];
            }
            if ($current && preg_match('/type=(\S+)/', $line, $m)) {
                $current['type'] = $m[1];
            }
            if ($current && preg_match('/mtu=(\d+)/', $line, $m)) {
                $current['mtu'] = $m[1];
            }
            if ($current && preg_match('/comment="([^"]*)"/', $line, $m)) {
                $current['comment'] = $m[1];
            }
        }

        if ($current) {
            $interfaces[] = $current;
        }

        return $interfaces;
    }

    private function parseSystemInfo(string $resourceOutput, string $identityOutput): array
    {
        $info = [
            'board_name'      => 'N/A',
            'version'         => 'N/A',
            'uptime'          => 'N/A',
            'identity'        => 'N/A',
            'cpu_load'        => null,
            'free_memory'     => null,
            'total_memory'    => null,
            'free_hdd_space'  => null,
            'total_hdd_space' => null,
        ];

        if (preg_match('/board-name:\s*(.+)/', $resourceOutput, $m))  { $info['board_name']     = trim($m[1]); }
        if (preg_match('/version:\s*(.+)/', $resourceOutput, $m))      { $info['version']        = trim($m[1]); }
        if (preg_match('/uptime:\s*(.+)/', $resourceOutput, $m))       { $info['uptime']         = trim($m[1]); }
        if (preg_match('/cpu-load:\s*(\d+)/', $resourceOutput, $m))    { $info['cpu_load']       = (int) $m[1]; }
        if (preg_match('/free-memory:\s*([^\r\n]+)/', $resourceOutput, $m))  { $info['free_memory']    = trim($m[1]); }
        if (preg_match('/total-memory:\s*([^\r\n]+)/', $resourceOutput, $m)) { $info['total_memory']   = trim($m[1]); }
        if (preg_match('/free-hdd-space:\s*([^\r\n]+)/', $resourceOutput, $m))  { $info['free_hdd_space']  = trim($m[1]); }
        if (preg_match('/total-hdd-space:\s*([^\r\n]+)/', $resourceOutput, $m)) { $info['total_hdd_space'] = trim($m[1]); }
        if (preg_match('/name:\s*(.+)/', $identityOutput, $m))         { $info['identity']       = trim($m[1]); }

        return $info;
    }

    private function isConfigurableInterface(array $iface): bool
    {
        $excluded = ['bridge', 'vlan', 'vrrp', 'vpls', 'ovpn-out', 'ovpn-in', 'wg', 'gre', 'ipip', 'eoip'];
        return !in_array($iface['type'] ?? '', $excluded);
    }
}
