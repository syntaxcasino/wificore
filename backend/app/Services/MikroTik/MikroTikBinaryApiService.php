<?php

declare(strict_types=1);

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\PasswordEncryptionService;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik Binary API Client (port 8728 / 8729-SSL)
 *
 * Implements the RouterOS binary API wire protocol as documented at:
 *   https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API
 *
 * Advantages over SSH for provisioning:
 *  - No SSH crypto overhead (key exchange, cipher negotiation) — critical for
 *    low-end MIPS devices (hAP lite, 650 MHz) where DH2048 takes 20-40 s.
 *  - No PTY / shell subprocess allocation — lower RAM spike.
 *  - Available on ALL RouterOS versions (v5+) including ROS6 devices.
 *  - Structured responses — no regex parsing of terminal output.
 *  - Works over the same WireGuard VPN tunnel as SSH.
 *
 * Wire protocol summary:
 *  - Every message is a "sentence" = sequence of "words" terminated by 0x00.
 *  - Each word is length-prefixed using a variable-length encoding (1-5 bytes).
 *  - Commands start with a command word (e.g. "/ip/address/add") followed by
 *    attribute words ("=key=value") and end with a zero-length word.
 *  - Router replies with !done, !re (record), !trap (error), or !fatal sentences.
 */
class MikroTikBinaryApiService implements MikroTikApiInterface
{
    private Router $router;

    /** @var resource|false */
    private $socket = false;

    private array $hostCandidates = [];
    private array $portCandidates = [];
    private ?string $connectedHost = null;
    private ?int $connectedPort = null;
    private string $username;
    private string $password;
    private int $timeout;

    public function __construct(Router $router, int $timeout = 30)
    {
        $this->router  = $router;
        $this->timeout = $timeout;
        $this->hostCandidates = $this->resolveHostCandidates($router);
        $this->portCandidates = $this->resolvePortCandidates($router);

        $this->username = $router->username;
        $decrypted = PasswordEncryptionService::safeDecrypt($router);
        if ($decrypted === null) {
            throw new \RuntimeException(
                'Unable to decrypt router credentials for Binary API (router id: ' . $router->id . ')'
            );
        }
        $this->password = $decrypted;
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Open TCP connection and authenticate via RouterOS login protocol.
     */
    public function connect(): void
    {
        $lastError = null;

        foreach ($this->hostCandidates as $host) {
            foreach ($this->portCandidates as $port) {
                try {
                    $this->openSocket($host, $port);
                    $this->login();
                    $this->connectedHost = $host;
                    $this->connectedPort = $port;
                    return;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $this->disconnect();
                }
            }
        }

        throw new \RuntimeException(
            'Binary API connection failed for router ' . $this->router->id . ': ' . ($lastError?->getMessage() ?? 'no reachable endpoint')
        );
    }

    /**
     * Disconnect (close TCP socket).
     */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = false;
        $this->connectedHost = null;
        $this->connectedPort = null;
    }

    /**
     * Test connectivity — connect, issue /system/resource/print, disconnect.
     * Always disconnects in the finally block to prevent socket leaks.
     */
    public function testConnection(): bool
    {
        try {
            $this->connect();
            $this->command('/system/resource/print');
            return true;
        } catch (\Throwable $e) {
            Log::warning('MikroTik Binary API connection test failed', [
                'router_id' => $this->router->id,
                'error'     => $e->getMessage(),
            ]);
            return false;
        } finally {
            $this->disconnect();
        }
    }

    private function resolveHostCandidates(Router $router): array
    {
        $candidates = [];
        foreach ([$router->vpn_ip, $router->ip_address] as $rawHost) {
            if (!is_string($rawHost) || trim($rawHost) === '') {
                continue;
            }
            $host = trim(explode('/', $rawHost, 2)[0]);
            if ($host !== '' && !in_array($host, $candidates, true)) {
                $candidates[] = $host;
            }
        }

        if (empty($candidates)) {
            throw new \RuntimeException('Router has no reachable IP for Binary API (router id: ' . $router->id . ')');
        }

        return $candidates;
    }

    private function resolvePortCandidates(Router $router): array
    {
        $configuredPort = (int) ($router->port ?? 0);
        $candidates = [];

        // This client speaks plain RouterOS API, not api-ssl. If the stored port points at
        // api-ssl or another management service, still try the plain API port first.
        if ($configuredPort > 0 && !in_array($configuredPort, [22, 80, 443, 8291, 8729], true)) {
            $candidates[] = $configuredPort;
        }

        $candidates[] = 8728;

        return array_values(array_unique($candidates));
    }

    private function openSocket(string $host, int $port): void
    {
        $errCode = 0;
        $errStr = '';

        $this->socket = @fsockopen($host, $port, $errCode, $errStr, $this->timeout);

        if ($this->socket === false) {
            throw new \RuntimeException(
                "Binary API connection failed to {$host}:{$port} — {$errStr} ({$errCode})"
            );
        }

        stream_set_timeout($this->socket, $this->timeout);
    }

    /**
     * Execute a RouterOS command and return all reply sentences.
     *
     * @param string   $command  RouterOS path, e.g. '/interface/bridge/add'
     * @param string[] $params   Attribute words without the leading '=', e.g. ['name=br1']
     * @return array[]  Array of reply records (each record is key→value array)
     * @throws \RuntimeException on !trap / !fatal or socket error
     */
    public function command(string $command, array $params = []): array
    {
        $sentence = [$command];
        foreach ($params as $param) {
            // Query words start with '?' — send verbatim (e.g. '?name=foo', '?~comment=pat').
            // All other words are attribute words and must be prefixed with '='.
            if (str_starts_with($param, '?')) {
                $sentence[] = $param;
            } else {
                $sentence[] = '=' . ltrim($param, '=');
            }
        }

        $this->writeSentence($sentence);
        return $this->readResponse();
    }

    /**
     * Convenience: execute a command and return the first record or empty array.
     */
    public function commandOne(string $command, array $params = []): array
    {
        $result = $this->command($command, $params);
        return $result[0] ?? [];
    }

    /**
     * Find the first record returned by a print command.
     *
     * The caller may pass exact match filters. When a matching record is found,
     * it is returned verbatim so helpers can safely decide whether to update or
     * re-use it without duplicating add operations.
     */
    private function findFirstRecord(string $path, array $filters = []): ?array
    {
        $query = [];
        foreach ($filters as $key => $value) {
            $query[] = '?' . $key . '=' . $value;
        }

        $records = $this->command($path . '/print', $query);
        return $records[0] ?? null;
    }

    /**
     * Determine whether a record satisfies all exact-match filters.
     */
    private function recordMatches(array $record, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            $actual = $record[$key] ?? null;
            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update an existing record by RouterOS internal ID.
     */
    private function updateRecord(string $path, string $id, array $params): array
    {
        $words = ['.id=' . $id];
        foreach ($params as $key => $value) {
            $words[] = $key . '=' . $value;
        }

        return $this->commandOne($path . '/set', $words);
    }

    /**
     * Add a new record on the target resource.
     */
    private function addRecord(string $path, array $params): array
    {
        $words = [];
        foreach ($params as $key => $value) {
            $words[] = $key . '=' . $value;
        }

        return $this->commandOne($path . '/add', $words);
    }

    // -------------------------------------------------------------------------
    // High-level provisioning helpers mirroring MikroTikRestApiService API
    // -------------------------------------------------------------------------

    public function createBridge(string $name, ?string $comment = null): array
    {
        // Check if bridge already exists
        $existing = $this->command('/interface/bridge/print', ['?name=' . $name]);
        
        if (!empty($existing)) {
            // Bridge exists - update comment if provided
            if ($comment && isset($existing[0]['.id'])) {
                return $this->commandOne('/interface/bridge/set', [
                    '.id' => $existing[0]['.id'],
                    'comment=' . $comment,
                ]);
            }
            return $existing[0] ?? [];
        }
        
        // Bridge doesn't exist - create it
        $params = ['name=' . $name];
        if ($comment) {
            $params[] = 'comment=' . $comment;
        }
        return $this->commandOne('/interface/bridge/add', $params);
    }

    public function addBridgePort(string $bridge, string $interface, ?string $comment = null): array
    {
        // Check if interface already has a bridge port entry
        $existing = $this->command('/interface/bridge/port/print', ['?interface=' . $interface]);
        
        if (!empty($existing)) {
            // Interface already has a bridge port
            $existingPort = $existing[0];
            
            // If already in the correct bridge, just update comment
            if (isset($existingPort['bridge']) && $existingPort['bridge'] === $bridge) {
                if ($comment && isset($existingPort['.id'])) {
                    return $this->commandOne('/interface/bridge/port/set', [
                        '.id' => $existingPort['.id'],
                        'comment=' . $comment,
                    ]);
                }
                return $existingPort;
            }
            
            // Interface is in a different bridge - remove it first
            if (isset($existingPort['.id'])) {
                $this->commandOne('/interface/bridge/port/remove', ['.id=' . $existingPort['.id']]);
            }
        }
        
        // Add the port to the bridge
        $params = ['bridge=' . $bridge, 'interface=' . $interface];
        if ($comment) {
            $params[] = 'comment=' . $comment;
        }
        return $this->commandOne('/interface/bridge/port/add', $params);
    }

    public function addVlan(string $name, int $vlanId, string $interface, ?string $comment = null): array
    {
        $desired = [
            'name' => $name,
            'vlan-id' => $vlanId,
            'interface' => $interface,
        ];
        if ($comment) {
            $desired['comment'] = $comment;
        }

        $existing = $this->findFirstRecord('/interface/vlan', ['name' => $name]);
        if ($existing && isset($existing['.id'])) {
            return $this->updateRecord('/interface/vlan', (string) $existing['.id'], $desired);
        }

        $params = [];
        foreach ($desired as $key => $value) {
            $params[] = $key . '=' . $value;
        }

        return $this->commandOne('/interface/vlan/add', $params);
    }

    public function addInterfaceListMember(string $list, string $interface): array
    {
        $existing = $this->findFirstRecord('/interface/list/member', [
            'list' => $list,
            'interface' => $interface,
        ]);

        if ($existing) {
            return $existing;
        }

        return $this->commandOne('/interface/list/member/add', [
            'list=' . $list,
            'interface=' . $interface,
        ]);
    }

    public function upsertResource(string $endpoint, array $matchFilters, array $data): array
    {
        $records = $this->fetch($endpoint);
        foreach ($records as $record) {
            if ($this->recordMatches($record, $matchFilters) && isset($record['.id'])) {
                return $this->updateRecord($endpoint, (string) $record['.id'], $data);
            }
        }

        return $this->addRecord($endpoint, $data);
    }

    public function createPppoeServer(
        string $serviceName,
        string $interface,
        string $profile,
        int    $maxMtu              = 1480,
        int    $maxMru              = 1480,
        bool   $oneSessionPerHost   = true,
        int    $keepaliveTimeout    = 30,
        string $authentication      = 'chap,mschap2'
    ): array {
        $desired = [
            'service-name' => $serviceName,
            'interface' => $interface,
            'default-profile' => $profile,
            'max-mtu' => $maxMtu,
            'max-mru' => $maxMru,
            'one-session-per-host' => $oneSessionPerHost ? 'yes' : 'no',
            'keepalive-timeout' => $keepaliveTimeout,
            'authentication' => $authentication,
            'disabled' => 'no',
        ];

        $existing = $this->findFirstRecord('/interface/pppoe-server/server', ['service-name' => $serviceName]);
        if ($existing && isset($existing['.id'])) {
            return $this->updateRecord('/interface/pppoe-server/server', (string) $existing['.id'], $desired);
        }

        $params = [];
        foreach ($desired as $key => $value) {
            $params[] = $key . '=' . $value;
        }

        return $this->commandOne('/interface/pppoe-server/server/add', $params);
    }

    public function addRadiusServer(
        string  $service,
        string  $address,
        string  $secret,
        int     $timeout = 3,
        ?string $comment = null
    ): array {
        $desired = [
            'service' => $service,
            'address' => $address,
            'secret' => $secret,
            'timeout' => $this->secondsToRosTime($timeout),
        ];
        if ($comment) {
            $desired['comment'] = $comment;
        }

        $existing = $comment
            ? $this->findFirstRecord('/radius', ['comment' => $comment])
            : $this->findFirstRecord('/radius', ['service' => $service, 'address' => $address]);

        if ($existing && isset($existing['.id'])) {
            return $this->updateRecord('/radius', (string) $existing['.id'], $desired);
        }

        $params = [];
        foreach ($desired as $key => $value) {
            $params[] = $key . '=' . $value;
        }

        return $this->commandOne('/radius/add', $params);
    }

    public function addFirewallFilterRule(array $params): array
    {
        if (isset($params['comment']) && is_string($params['comment']) && $params['comment'] !== '') {
            $existing = $this->findFirstRecord('/ip/firewall/filter', ['comment' => $params['comment']]);
            if ($existing && isset($existing['.id'])) {
                return $this->updateRecord('/ip/firewall/filter', (string) $existing['.id'], $params);
            }
        }

        $words = [];
        foreach ($params as $k => $v) {
            $words[] = $k . '=' . $v;
        }
        return $this->commandOne('/ip/firewall/filter/add', $words);
    }

    public function addNatRule(array $params): array
    {
        if (isset($params['comment']) && is_string($params['comment']) && $params['comment'] !== '') {
            $existing = $this->findFirstRecord('/ip/firewall/nat', ['comment' => $params['comment']]);
            if ($existing && isset($existing['.id'])) {
                return $this->updateRecord('/ip/firewall/nat', (string) $existing['.id'], $params);
            }
        }

        $words = [];
        foreach ($params as $k => $v) {
            $words[] = $k . '=' . $v;
        }
        return $this->commandOne('/ip/firewall/nat/add', $words);
    }

    public function setConnectionTracking(int $tcpEstablishedTimeout = 3600, int $udpTimeout = 30): array
    {
        // RouterOS time parameters require a unit suffix — bare integers cause !trap.
        // Convert seconds to RouterOS time string: 3600 → '1h', 30 → '30s', etc.
        return $this->commandOne('/ip/firewall/connection/tracking/set', [
            'tcp-established-timeout=' . $this->secondsToRosTime($tcpEstablishedTimeout),
            'udp-timeout='             . $this->secondsToRosTime($udpTimeout),
        ]);
    }

    /**
     * Convert an integer number of seconds to a RouterOS time string.
     * RouterOS accepts: Xs, Xm, Xh, Xd — or compound like 1h30m.
     * We use the largest single unit that divides evenly, else fall back to Xs.
     */
    private function secondsToRosTime(int $seconds): string
    {
        if ($seconds === 0) {
            return '0s';
        }
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . 'd';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . 'h';
        }
        if ($seconds % 60 === 0) {
            return ($seconds / 60) . 'm';
        }
        return $seconds . 's';
    }

    /**
     * Remove items whose 'comment' field contains $commentPattern as a substring.
     *
     * The RouterOS Binary API does NOT support regex (~ operator) in query words.
     * Official docs: "Regular expressions are not supported in API, so do not try
     * to send a query with the ~ symbol". We therefore fetch all items and filter
     * in PHP using str_contains().
     */
    public function removeByComment(string $path, string $commentPattern): void
    {
        try {
            $items = $this->command($path . '/print');
            foreach ($items as $item) {
                if (!isset($item['.id'])) {
                    continue;
                }
                $comment = $item['comment'] ?? '';
                if (str_contains($comment, $commentPattern)) {
                    try {
                        $this->command($path . '/remove', ['.id=' . $item['.id']]);
                    } catch (\Throwable $e) {
                        // Non-fatal — item may already be gone
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Binary API removeByComment failed', [
                'router_id' => $this->router->id,
                'path'      => $path,
                'pattern'   => $commentPattern,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    public function removeFirewallFilterByComment(string $commentPattern): void
    {
        $this->removeByComment('/ip/firewall/filter', $commentPattern);
    }

    public function removeNatByComment(string $comment): void
    {
        $this->removeByComment('/ip/firewall/nat', $comment);
    }

    public function removeRadiusByComment(string $commentPattern): void
    {
        $this->removeByComment('/radius', $commentPattern);
    }

    public function removeBridge(string $name): bool
    {
        try {
            $items = $this->command('/interface/bridge/print', ['?name=' . $name]);
            foreach ($items as $item) {
                if (isset($item['.id'])) {
                    $this->command('/interface/bridge/remove', ['.id=' . $item['.id']]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Binary API removeBridge failed', [
                'router_id' => $this->router->id,
                'bridge'    => $name,
                'error'     => $e->getMessage(),
            ]);
        }
        return false;
    }

    public function removeBridgePort(string $interface): bool
    {
        try {
            $items = $this->command('/interface/bridge/port/print', ['?interface=' . $interface]);
            foreach ($items as $item) {
                if (isset($item['.id'])) {
                    $this->command('/interface/bridge/port/remove', ['.id=' . $item['.id']]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Binary API removeBridgePort failed', [
                'router_id' => $this->router->id,
                'interface' => $interface,
                'error'     => $e->getMessage(),
            ]);
        }
        return false;
    }

    public function removeVlan(string $name): bool
    {
        try {
            $items = $this->command('/interface/vlan/print', ['?name=' . $name]);
            foreach ($items as $item) {
                if (isset($item['.id'])) {
                    $this->command('/interface/vlan/remove', ['.id=' . $item['.id']]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Binary API removeVlan failed', [
                'router_id' => $this->router->id,
                'vlan'      => $name,
                'error'     => $e->getMessage(),
            ]);
        }
        return false;
    }

    public function removePppoeServer(string $serviceName): bool
    {
        try {
            $items = $this->command('/interface/pppoe-server/server/print', ['?service-name=' . $serviceName]);
            foreach ($items as $item) {
                if (isset($item['.id'])) {
                    $this->command('/interface/pppoe-server/server/remove', ['.id=' . $item['.id']]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Binary API removePppoeServer failed', [
                'router_id'    => $this->router->id,
                'service_name' => $serviceName,
                'error'        => $e->getMessage(),
            ]);
        }
        return false;
    }

    public function pppoeServerExists(string $serviceName): bool
    {
        try {
            $items = $this->command('/interface/pppoe-server/server/print', ['?service-name=' . $serviceName]);
            return !empty($items);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Execute arbitrary command (compatibility shim used by ApiConfigurators).
     *
     * Accepts EITHER:
     *   - Associative array: ['name' => 'br1', 'comment' => 'x']  → '=name=br1', '=comment=x'
     *   - Indexed array:     ['name=br1', 'comment=x']            → passed through as-is
     */
    public function executeCommand(string $endpoint, array $params = []): array
    {
        $words = [];
        foreach ($params as $k => $v) {
            if (is_int($k)) {
                // Already in 'key=value' form
                $words[] = (string) $v;
            } else {
                $words[] = $k . '=' . $v;
            }
        }
        return $this->commandOne($endpoint, $words);
    }

    /**
     * Fetch list from endpoint (compatibility shim).
     *
     * Callers pass paths WITHOUT a trailing '/print' (e.g. '/ip/pool'),
     * matching the REST API convention used by the configurators.
     */
    public function fetch(string $endpoint): array
    {
        // Strip trailing '/print' if caller already appended it (defensive)
        $path = rtrim($endpoint, '/');
        if (str_ends_with($path, '/print')) {
            $path = substr($path, 0, -strlen('/print'));
        }
        return $this->command($path . '/print');
    }

    // -------------------------------------------------------------------------
    // Binary wire-protocol implementation
    // -------------------------------------------------------------------------

    /**
     * RouterOS API login sequence.
     * ROS6: challenge/MD5 login. ROS7: plaintext token login.
     * We try plaintext first (works on both), fall back to MD5 if rejected.
     *
     * ROS7  one-step: /login =name=X =password=Y  → !done
     * ROS6  two-step: /login =name=X =password=Y  → !trap (wrong password format)
     *                 /login                       → !done =ret=<challenge_hex>
     *                 /login =name=X =response=00<md5> → !done
     */
    private function login(): void
    {
        // Attempt modern (ROS7+) plaintext login
        try {
            $this->command('/login', [
                'name='     . $this->username,
                'password=' . $this->password,
            ]);
            // !done with no !trap = success
            return;
        } catch (\RuntimeException $e) {
            // ROS6 responds with !trap to the plaintext login attempt.
            // Any !trap here is a signal to try the two-step MD5 path.
            // Re-throw non-API errors (socket failures etc.).
            if (!str_contains($e->getMessage(), '!trap')) {
                throw $e;
            }
        }

        // ROS6 two-step challenge/MD5 login
        $response = $this->command('/login');
        $challenge = $response[0]['ret'] ?? null;

        if ($challenge === null) {
            throw new \RuntimeException(
                'Binary API login failed: no challenge returned (router id: ' . $this->router->id . ')'
            );
        }

        // MD5(0x00 + password + challenge_bytes)
        $challengeBytes = pack('H*', $challenge);
        $md5 = md5(chr(0) . $this->password . $challengeBytes);

        $this->command('/login', [
            'name='     . $this->username,
            'response=00' . $md5,
        ]);
    }

    /**
     * Write a sentence (array of words) to the socket.
     * Each word is length-prefixed per the RouterOS binary protocol.
     */
    private function writeSentence(array $words): void
    {
        $data = '';
        foreach ($words as $word) {
            $data .= $this->encodeLength(strlen($word)) . $word;
        }
        $data .= chr(0); // End-of-sentence zero word

        $written = @fwrite($this->socket, $data);
        if ($written === false || $written !== strlen($data)) {
            throw new \RuntimeException('Binary API socket write failed (router id: ' . $this->router->id . ')');
        }
    }

    /**
     * Read one or more reply sentences from the socket until !done or !fatal.
     * Returns an array of record arrays (each !re sentence becomes one record).
     */
    private function readResponse(): array
    {
        $records = [];

        while (true) {
            $sentence = $this->readSentence();
            if (empty($sentence)) {
                continue;
            }

            $type = $sentence[0];

            if ($type === '!re') {
                $record = [];
                foreach (array_slice($sentence, 1) as $word) {
                    if (str_starts_with($word, '=')) {
                        $word = substr($word, 1); // strip leading '='
                        [$key, $value] = explode('=', $word, 2) + ['', ''];
                        $record[$key] = $value;
                    }
                }
                $records[] = $record;
            } elseif ($type === '!done' || $type === '!empty') {
                // !done  — normal end of response; MAY carry attribute words.
                //          ROS6 login delivers =ret=<challenge> inside !done
                //          (per docs example: ">>> !done >>> =ret=93b438ec9b...").
                // !empty — ROS 7.18+: command succeeded but returned no records.
                // Parse any attribute words so callers like login() see them.
                $doneRecord = [];
                foreach (array_slice($sentence, 1) as $word) {
                    if (str_starts_with($word, '=')) {
                        $word = substr($word, 1);
                        [$key, $value] = explode('=', $word, 2) + ['', ''];
                        $doneRecord[$key] = $value;
                    }
                }
                if (!empty($doneRecord)) {
                    $records[] = $doneRecord;
                }
                break;
            } elseif ($type === '!trap' || $type === '!fatal') {
                // Extract error message
                $message = '';
                foreach (array_slice($sentence, 1) as $word) {
                    if (str_starts_with($word, '=message=')) {
                        $message = substr($word, strlen('=message='));
                        break;
                    }
                }
                throw new \RuntimeException(
                    "RouterOS API error ({$type}): {$message} (router id: " . $this->router->id . ')'
                );
            }
            // !continue, etc. — keep reading
        }

        return $records;
    }

    /**
     * Read one sentence (array of words) from the socket.
     */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $length = $this->decodeLength();
            if ($length === 0) {
                break; // End of sentence
            }
            $word = $this->readBytes($length);
            $words[] = $word;
        }
        return $words;
    }

    /**
     * Encode word length using RouterOS variable-length encoding.
     * 1 byte  : length < 0x80
     * 2 bytes : length < 0x4000
     * 3 bytes : length < 0x200000
     * 4 bytes : length < 0x10000000
     * 5 bytes : longer (rare)
     */
    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return pack('n', $length);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return pack('N', $length) [1] . pack('N', $length) [2] . pack('N', $length) [3];
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return pack('N', $length);
        }
        return chr(0xF0) . pack('N', $length);
    }

    /**
     * Read and decode a variable-length word length from the socket.
     */
    private function decodeLength(): int
    {
        $b = ord($this->readBytes(1));

        if (($b & 0x80) === 0x00) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            $b2 = ord($this->readBytes(1));
            return (($b & 0x3F) << 8) | $b2;  // clear marker bits 7+6, keep payload 5-0
        }
        if (($b & 0xE0) === 0xC0) {
            $raw = $this->readBytes(2);
            return (($b & 0x1F) << 16) | (ord($raw[0]) << 8) | ord($raw[1]);  // clear 7+6+5
        }
        if (($b & 0xF0) === 0xE0) {
            $raw = $this->readBytes(3);
            return (($b & 0x0F) << 24) | (ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]);  // clear 7+6+5+4
        }
        // 5-byte form
        $raw = $this->readBytes(4);
        return unpack('N', $raw)[1];
    }

    /**
     * Read exactly $n bytes from the socket.
     */
    private function readBytes(int $n): string
    {
        $data = '';
        $remaining = $n;

        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out'] ?? false) {
                    throw new \RuntimeException(
                        'Binary API socket timed out reading ' . $n . ' bytes (router id: ' . $this->router->id . ')'
                    );
                }
                throw new \RuntimeException(
                    'Binary API socket closed unexpectedly while reading (router id: ' . $this->router->id . ')'
                );
            }
            $data      .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
