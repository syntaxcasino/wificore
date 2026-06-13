<?php

namespace App\Services\RouterDriver;

use App\Models\Router;
use App\Services\MikroTik\MikroTikRestApiService;
use App\Services\MikrotikSshService;
use App\Services\MikrotikSnmpService;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik Router Driver
 * 
 * Adapter that wraps existing MikroTik services to implement the RouterDriverInterface.
 * Provides multi-vendor abstraction while reusing proven MikroTik service code.
 */
class MikroTikDriver implements RouterDriverInterface
{
    private MikrotikSshService $sshService;
    private MikroTikRestApiService $apiService;
    private ?MikrotikSnmpService $snmpService;

    public function __construct(
        MikrotikSshService $sshService,
        MikroTikRestApiService $apiService,
        ?MikrotikSnmpService $snmpService = null
    ) {
        $this->sshService = $sshService;
        $this->apiService = $apiService;
        $this->snmpService = $snmpService;
    }

    /**
     * Get driver capabilities
     */
    public function getCapabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            vendor: 'MikroTik',
            supportedModels: 'RouterBOARD*,hAP*,cRS*,SXT*,LHG*,RB*,CCR*,CRS*,mAP*',
            supportsPppoe: true,
            supportsHotspot: true,
            supportsVlan: true,
            supportsCoA: true,
            supportsRadius: true,
            supportsRestApi: true,
            supportsSsh: true,
            supportsSnmp: true,
            supportsApiSsl: true,
            supportedAuthMethods: ['pap', 'chap', 'mschap2', 'eap'],
            minFirmwareVersion: '6.40',
            maxFirmwareVersion: '7.x'
        );
    }

    /**
     * Detect device information
     */
    public function detectDevice(Router $router): DeviceInfo
    {
        try {
            $systemInfo = $this->sshService->getSystemInfo($router);
            
            return new DeviceInfo(
                vendor: 'MikroTik',
                model: $systemInfo['board-name'] ?? $router->model,
                firmwareVersion: $systemInfo['version'] ?? 'unknown',
                serialNumber: $systemInfo['serial-number'] ?? 'unknown',
                hardwareVersion: $systemInfo['factory-firmware'] ?? null,
                boardName: $systemInfo['board-name'] ?? null,
                identity: $systemInfo['identity'] ?? null,
                uptime: $systemInfo['uptime'] ?? null
            );
        } catch (\Exception $e) {
            Log::error('Failed to detect MikroTik device', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return new DeviceInfo(
                vendor: 'MikroTik',
                model: $router->model ?? 'unknown',
                firmwareVersion: 'unknown',
                serialNumber: 'unknown'
            );
        }
    }

    /**
     * Check if this driver supports the router
     */
    public function supports(Router $router): bool
    {
        // Check explicit vendor
        if ($router->vendor && strtolower($router->vendor) === 'mikrotik') {
            return true;
        }

        // Check model patterns
        $model = strtolower($router->model ?? '');
        $mikrotikPatterns = [
            'mikrotik', 'routerboard', 'hap', 'crs', 'rb', 'sxt', 
            'lhg', 'ccr', 'map', 'crs', 'cap'
        ];

        foreach ($mikrotikPatterns as $pattern) {
            if (str_contains($model, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate configuration script
     */
    public function generateConfig(array $config): string
    {
        $serviceType = $config['service_type'] ?? 'pppoe';
        
        // Delegate to existing generator services
        switch ($serviceType) {
            case 'hotspot':
                return $this->generateHotspotConfig($config);
            case 'pppoe':
                return $this->generatePppoeConfig($config);
            case 'hybrid':
                return $this->generateHybridConfig($config);
            default:
                throw new \InvalidArgumentException("Unknown service type: {$serviceType}");
        }
    }

    /**
     * Apply configuration to router
     */
    public function applyConfig(Router $router, string $config): bool
    {
        try {
            $result = $this->sshService->executeScript($router, $config);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Failed to apply MikroTik config', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify configuration on router
     */
    public function verifyConfig(Router $router): VerificationResult
    {
        try {
            $checks = [];
            
            // Check interfaces
            $interfaces = $this->sshService->getInterfaces($router);
            $checks['interfaces'] = !empty($interfaces);
            
            // Check RADIUS
            $radius = $this->sshService->executeCommand($router, '/radius print count-only');
            $checks['radius_configured'] = trim($radius['output'] ?? '0') !== '0';
            
            // Check PPPoE/Hotspot servers
            $pppoe = $this->sshService->executeCommand($router, '/interface pppoe-server server print count-only');
            $hotspot = $this->sshService->executeCommand($router, '/ip hotspot print count-only');
            $checks['services_running'] = 
                trim($pppoe['output'] ?? '0') !== '0' || 
                trim($hotspot['output'] ?? '0') !== '0';
            
            $allPassed = !in_array(false, $checks, true);
            
            return new VerificationResult(
                valid: $allPassed,
                checks: $checks
            );
            
        } catch (\Exception $e) {
            return new VerificationResult(
                valid: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Get running configuration
     */
    public function getRunningConfig(Router $router): string
    {
        try {
            $result = $this->sshService->executeCommand($router, '/export compact');
            return $result['output'] ?? '';
        } catch (\Exception $e) {
            Log::error('Failed to get MikroTik running config', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Check router connectivity
     */
    public function checkConnectivity(Router $router): ConnectionStatus
    {
        $startTime = microtime(true);
        
        try {
            // Try SSH first
            $sshResult = $this->sshService->testConnection($router);
            if ($sshResult['success']) {
                $latency = (int) ((microtime(true) - $startTime) * 1000);
                return new ConnectionStatus(
                    reachable: true,
                    latencyMs: $latency,
                    connectionMethod: 'ssh'
                );
            }

            // Fall back to API
            $apiResult = $this->apiService->testConnection($router);
            if ($apiResult['success']) {
                $latency = (int) ((microtime(true) - $startTime) * 1000);
                return new ConnectionStatus(
                    reachable: true,
                    latencyMs: $latency,
                    connectionMethod: 'api'
                );
            }

            return new ConnectionStatus(
                reachable: false,
                latencyMs: 0,
                error: $sshResult['error'] ?? $apiResult['error'] ?? 'Connection failed'
            );
            
        } catch (\Exception $e) {
            return new ConnectionStatus(
                reachable: false,
                latencyMs: 0,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Execute command on router
     */
    public function executeCommand(Router $router, string $command): CommandResult
    {
        try {
            $result = $this->sshService->executeCommand($router, $command);
            
            return new CommandResult(
                success: $result['success'] ?? false,
                output: $result['output'] ?? '',
                error: $result['error'] ?? null,
                exitCode: $result['exit_code'] ?? 0
            );
        } catch (\Exception $e) {
            return new CommandResult(
                success: false,
                output: '',
                error: $e->getMessage(),
                exitCode: -1
            );
        }
    }

    /**
     * Get system information
     */
    public function getSystemInfo(Router $router): SystemInfo
    {
        try {
            $info = $this->sshService->getSystemInfo($router);
            $resources = $this->sshService->getResourceUsage($router);

            return new SystemInfo(
                version: $info['version'] ?? 'unknown',
                architecture: $info['architecture-name'] ?? 'unknown',
                boardName: $info['board-name'] ?? 'unknown',
                cpuCount: (int) ($info['cpu-count'] ?? 1),
                memoryTotal: (int) ($resources['total-memory'] ?? 0),
                memoryFree: (int) ($resources['free-memory'] ?? 0),
                cpuLoad: (float) ($resources['cpu-load'] ?? 0),
                temperature: isset($resources['temperature']) ? (float) $resources['temperature'] : null,
                uptime: $info['uptime'] ?? 'unknown'
            );
        } catch (\Exception $e) {
            Log::error('Failed to get MikroTik system info', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get active sessions
     */
    public function getActiveSessions(Router $router): array
    {
        $sessions = [];

        try {
            // Get PPPoE sessions
            $pppoeResult = $this->sshService->executeCommand(
                $router, 
                '/ppp active print without-paging'
            );
            if ($pppoeResult['success']) {
                $sessions['pppoe'] = $this->parsePppoeSessions($pppoeResult['output']);
            }

            // Get Hotspot sessions
            $hotspotResult = $this->sshService->executeCommand(
                $router,
                '/ip hotspot active print without-paging'
            );
            if ($hotspotResult['success']) {
                $sessions['hotspot'] = $this->parseHotspotSessions($hotspotResult['output']);
            }

        } catch (\Exception $e) {
            Log::error('Failed to get MikroTik active sessions', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sessions;
    }

    /**
     * Disconnect specific session
     */
    public function disconnectSession(Router $router, string $sessionId): bool
    {
        try {
            // Try PPPoE disconnect
            $result = $this->sshService->executeCommand(
                $router,
                "/ppp active remove [find name=\"{$sessionId}\"]"
            );
            
            if ($result['success']) {
                return true;
            }

            // Try Hotspot disconnect
            $result = $this->sshService->executeCommand(
                $router,
                "/ip hotspot active remove [find user=\"{$sessionId}\"]"
            );

            return $result['success'] ?? false;
            
        } catch (\Exception $e) {
            Log::error('Failed to disconnect MikroTik session', [
                'router_id' => $router->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reboot router
     */
    public function reboot(Router $router, bool $soft = true): bool
    {
        try {
            $command = $soft ? '/system reboot' : '/system shutdown';
            $result = $this->sshService->executeCommand($router, $command);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Failed to reboot MikroTik', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Backup configuration
     */
    public function backupConfig(Router $router): string
    {
        return $this->getRunningConfig($router);
    }

    /**
     * Restore configuration
     */
    public function restoreConfig(Router $router, string $config): bool
    {
        return $this->applyConfig($router, $config);
    }

    /**
     * Get interfaces
     */
    public function getInterfaces(Router $router): array
    {
        try {
            $result = $this->sshService->getInterfaces($router);
            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to get MikroTik interfaces', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get resource usage
     */
    public function getResourceUsage(Router $router): ResourceUsage
    {
        try {
            $resources = $this->sshService->getResourceUsage($router);

            $memoryTotal = (int) ($resources['total-memory'] ?? 0);
            $memoryFree = (int) ($resources['free-memory'] ?? 0);
            $memoryUsed = $memoryTotal - $memoryFree;
            $memoryUsagePercent = $memoryTotal > 0 ? ($memoryUsed / $memoryTotal) * 100 : 0;

            return new ResourceUsage(
                cpuLoad: (float) ($resources['cpu-load'] ?? 0),
                memoryUsed: $memoryUsed,
                memoryTotal: $memoryTotal,
                memoryUsagePercent: round($memoryUsagePercent, 2)
            );
        } catch (\Exception $e) {
            Log::error('Failed to get MikroTik resource usage', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return new ResourceUsage(0, 0, 0, 0);
        }
    }

    // Private helper methods

    private function generateHotspotConfig(array $config): string
    {
        // Delegate to existing ZeroConfigHotspotGenerator
        $generator = app(\App\Services\MikroTik\ZeroConfigHotspotGenerator::class);
        return $generator->generate($config);
    }

    private function generatePppoeConfig(array $config): string
    {
        // Delegate to existing ZeroConfigPPPoEGenerator
        $generator = app(\App\Services\MikroTik\ZeroConfigPPPoEGenerator::class);
        return $generator->generate($config);
    }

    private function generateHybridConfig(array $config): string
    {
        // Delegate to existing ZeroConfigHybridGenerator
        $generator = app(\App\Services\MikroTik\ZeroConfigHybridGenerator::class);
        return $generator->generate($config);
    }

    private function parsePppoeSessions(string $output): array
    {
        // Parse MikroTik PPPoE active output
        $sessions = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+\S+\s+(.+)$/', $line, $matches)) {
                $sessions[] = [
                    'id' => $matches[1],
                    'name' => trim($matches[2]),
                ];
            }
        }
        
        return $sessions;
    }

    private function parseHotspotSessions(string $output): array
    {
        // Parse MikroTik Hotspot active output
        $sessions = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+\S+\s+(.+)$/', $line, $matches)) {
                $sessions[] = [
                    'id' => $matches[1],
                    'user' => trim($matches[2]),
                ];
            }
        }
        
        return $sessions;
    }
}
