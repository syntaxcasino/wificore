<?php

namespace App\Services\Deployment;

use App\Models\Router;
use App\Models\ConfigSnapshot;
use App\Services\RouterDriver\DriverRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Configuration Drift Detector
 * 
 * Detects unauthorized or unexpected configuration changes on routers
 * by comparing current running config against expected baseline.
 */
class ConfigDriftDetector
{
    private DriverRegistry $driverRegistry;

    public function __construct(?DriverRegistry $driverRegistry = null)
    {
        $this->driverRegistry = $driverRegistry ?? app(DriverRegistry::class);
    }

    /**
     * Create configuration snapshot for baseline
     * 
     * @param Router $router Router to snapshot
     * @return ConfigSnapshot Created snapshot
     */
    public function snapshotConfiguration(Router $router): ConfigSnapshot
    {
        try {
            $driver = $this->driverRegistry->getDriverForRouter($router);
            
            // Get running configuration
            $config = $driver->getRunningConfig($router);
            
            // Parse into structured format
            $parsedConfig = $this->parseConfig($config);
            
            // Create snapshot
            $snapshot = ConfigSnapshot::create([
                'router_id' => $router->id,
                'config_text' => $config,
                'config_hash' => hash('sha256', $config),
                'parsed_config' => $parsedConfig,
                'created_at' => now(),
            ]);

            Log::info('Configuration snapshot created', [
                'router_id' => $router->id,
                'snapshot_id' => $snapshot->id,
                'hash' => $snapshot->config_hash,
            ]);

            return $snapshot;

        } catch (\Exception $e) {
            Log::error('Failed to create config snapshot', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Detect configuration drift
     * 
     * @param Router $router Router to check
     * @param ConfigSnapshot|null $baseline Snapshot to compare against (null = use latest)
     * @return DriftReport Drift detection results
     */
    public function detectDrift(Router $router, ?ConfigSnapshot $baseline = null): DriftReport
    {
        try {
            // Get baseline snapshot
            $baseline = $baseline ?? $this->getLatestSnapshot($router);
            
            if (!$baseline) {
                return new DriftReport(
                    routerId: $router->id,
                    hasDrift: false,
                    error: 'No baseline snapshot available'
                );
            }

            // Get current configuration
            $driver = $this->driverRegistry->getDriverForRouter($router);
            $currentConfig = $driver->getRunningConfig($router);
            $currentHash = hash('sha256', $currentConfig);

            // Quick hash comparison
            if ($currentHash === $baseline->config_hash) {
                return new DriftReport(
                    routerId: $router->id,
                    hasDrift: false,
                    baselineSnapshotId: $baseline->id,
                    currentHash: $currentHash
                );
            }

            // Parse current config
            $parsedCurrent = $this->parseConfig($currentConfig);
            $parsedBaseline = $baseline->parsed_config ?? $this->parseConfig($baseline->config_text);

            // Calculate differences
            $differences = $this->calculateDifferences($parsedBaseline, $parsedCurrent);

            // Create drift report
            $report = new DriftReport(
                routerId: $router->id,
                hasDrift: !empty($differences),
                baselineSnapshotId: $baseline->id,
                currentHash: $currentHash,
                differences: $differences,
                severity: $this->calculateSeverity($differences),
                detectedAt: now()
            );

            // Log drift detection
            if ($report->hasDrift) {
                Log::warning('Configuration drift detected', [
                    'router_id' => $router->id,
                    'differences_count' => count($differences),
                    'severity' => $report->severity,
                ]);
            }

            return $report;

        } catch (\Exception $e) {
            Log::error('Failed to detect config drift', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return new DriftReport(
                routerId: $router->id,
                hasDrift: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Auto-remediate configuration drift
     * 
     * @param Router $router Router to remediate
     * @param ConfigSnapshot|null $baseline Baseline to restore (null = use latest)
     * @return RemediationResult Remediation status
     */
    public function autoRemediate(Router $router, ?ConfigSnapshot $baseline = null): RemediationResult
    {
        try {
            $baseline = $baseline ?? $this->getLatestSnapshot($router);
            
            if (!$baseline) {
                return new RemediationResult(
                    success: false,
                    message: 'No baseline snapshot available for remediation'
                );
            }

            // Check if auto-remediation is enabled for this router
            if (!$router->auto_remediate) {
                return new RemediationResult(
                    success: false,
                    message: 'Auto-remediation disabled for this router',
                    requiresManualApproval: true
                );
            }

            $driver = $this->driverRegistry->getDriverForRouter($router);
            
            // Restore configuration
            $success = $driver->restoreConfig($router, $baseline->config_text);

            if ($success) {
                // Verify restoration
                $verification = $driver->verifyConfig($router);
                
                Log::info('Auto-remediation completed', [
                    'router_id' => $router->id,
                    'snapshot_id' => $baseline->id,
                    'verification_passed' => $verification->valid,
                ]);

                return new RemediationResult(
                    success: true,
                    message: 'Configuration restored to baseline',
                    restoredFromSnapshotId: $baseline->id,
                    verificationPassed: $verification->valid
                );
            } else {
                return new RemediationResult(
                    success: false,
                    message: 'Failed to restore configuration',
                    requiresManualApproval: true
                );
            }

        } catch (\Exception $e) {
            Log::error('Auto-remediation failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return new RemediationResult(
                success: false,
                message: 'Exception: ' . $e->getMessage(),
                requiresManualApproval: true
            );
        }
    }

    /**
     * Get latest snapshot for router
     */
    public function getLatestSnapshot(Router $router): ?ConfigSnapshot
    {
        return ConfigSnapshot::where('router_id', $router->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * Get all snapshots for router
     */
    public function getSnapshots(Router $router, int $limit = 10): array
    {
        return ConfigSnapshot::where('router_id', $router->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Clean up old snapshots
     */
    public function cleanupOldSnapshots(int $keepCount = 10): int
    {
        $deleted = 0;
        
        $routers = DB::table('config_snapshots')
            ->select('router_id')
            ->distinct()
            ->pluck('router_id');

        foreach ($routers as $routerId) {
            $toDelete = ConfigSnapshot::where('router_id', $routerId)
                ->latest('created_at')
                ->skip($keepCount)
                ->take(100)
                ->get();

            foreach ($toDelete as $snapshot) {
                $snapshot->delete();
                $deleted++;
            }
        }

        Log::info('Old snapshots cleaned up', [
            'deleted_count' => $deleted,
            'kept_per_router' => $keepCount,
        ]);

        return $deleted;
    }

    /**
     * Parse configuration into structured format
     */
    private function parseConfig(string $config): array
    {
        $parsed = [
            'sections' => [],
            'commands' => [],
        ];

        $lines = explode("\n", $config);
        $currentSection = '';

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Detect section changes
            if (preg_match('/^\/([a-z-]+)\s*(.*)$/', $line, $matches)) {
                $currentSection = $matches[1];
                $parsed['sections'][$currentSection][] = $line;
            }

            $parsed['commands'][] = [
                'section' => $currentSection,
                'command' => $line,
                'hash' => hash('md5', $line),
            ];
        }

        return $parsed;
    }

    /**
     * Calculate differences between configurations
     */
    private function calculateDifferences(array $baseline, array $current): array
    {
        $differences = [];

        // Get baseline commands as hash map
        $baselineHashes = [];
        foreach ($baseline['commands'] ?? [] as $cmd) {
            $baselineHashes[$cmd['hash']] = $cmd;
        }

        // Get current commands as hash map
        $currentHashes = [];
        foreach ($current['commands'] ?? [] as $cmd) {
            $currentHashes[$cmd['hash']] = $cmd;
        }

        // Find added commands
        foreach ($currentHashes as $hash => $cmd) {
            if (!isset($baselineHashes[$hash])) {
                $differences[] = [
                    'type' => 'added',
                    'section' => $cmd['section'],
                    'command' => $cmd['command'],
                ];
            }
        }

        // Find removed commands
        foreach ($baselineHashes as $hash => $cmd) {
            if (!isset($currentHashes[$hash])) {
                $differences[] = [
                    'type' => 'removed',
                    'section' => $cmd['section'],
                    'command' => $cmd['command'],
                ];
            }
        }

        return $differences;
    }

    /**
     * Calculate severity of drift
     */
    private function calculateSeverity(array $differences): string
    {
        $criticalSections = ['firewall', 'user', 'password', 'ssh', 'service'];
        $hasCriticalChanges = false;

        foreach ($differences as $diff) {
            $section = strtolower($diff['section'] ?? '');
            foreach ($criticalSections as $critical) {
                if (str_contains($section, $critical)) {
                    $hasCriticalChanges = true;
                    break 2;
                }
            }
        }

        if ($hasCriticalChanges) {
            return 'critical';
        }

        $count = count($differences);
        if ($count > 20) {
            return 'high';
        } elseif ($count > 5) {
            return 'medium';
        }

        return 'low';
    }
}
