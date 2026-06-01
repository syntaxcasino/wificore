<?php

namespace App\Services\Deployment;

use App\Models\Router;
use App\Services\RouterDriver\DriverRegistry;
use App\Services\RouterDriver\RouterDriverInterface;
use Illuminate\Support\Facades\Log;

class DeploymentSafetyService
{
    public function __construct(
        private readonly DriverRegistry $driverRegistry,
        private readonly ConfigDriftDetector $driftDetector,
    ) {
    }

    public function deployWithSafety(Router $router, string $config, array $options = []): DeploymentSafetyResult
    {
        $basePolicy = config('deployment_safety');
        $policy = array_merge(is_array($basePolicy) ? $basePolicy : [], $options);
        $requireSnapshot = (bool) ($policy['require_pre_deploy_snapshot'] ?? true);
        $allowSnapshotExemption = (bool) ($policy['allow_snapshot_exemption'] ?? false);
        $verifyAfterDeploy = (bool) ($policy['verify_after_deploy'] ?? true);
        $rollbackOnFailedApply = (bool) ($policy['auto_rollback_on_failed_apply'] ?? true);
        $rollbackOnFailedChecks = (bool) ($policy['auto_rollback_on_failed_checks'] ?? true);

        try {
            $driver = $this->driverRegistry->getDriverForRouter($router);
            $snapshot = null;
            $snapshotTaken = false;

            if ($requireSnapshot && ! $allowSnapshotExemption) {
                $snapshot = $this->driftDetector->snapshotConfiguration($router);
                $snapshotTaken = true;
            }

            $applySuccess = $driver->applyConfig($router, $config);
            if (! $applySuccess) {
                $rolledBack = $snapshot && $rollbackOnFailedApply
                    ? $this->restoreSnapshot($driver, $router, $snapshot->config_text)
                    : false;

                return new DeploymentSafetyResult(
                    success: false,
                    snapshotId: $snapshot?->id,
                    snapshotTaken: $snapshotTaken,
                    verificationPassed: false,
                    rolledBack: $rolledBack,
                    message: 'Configuration apply failed',
                    error: $rolledBack ? null : 'apply_failed'
                );
            }

            if (! $verifyAfterDeploy) {
                return new DeploymentSafetyResult(
                    success: true,
                    snapshotId: $snapshot?->id,
                    snapshotTaken: $snapshotTaken,
                    verificationPassed: true,
                    rolledBack: false,
                    message: 'Configuration applied without post-deploy verification'
                );
            }

            $verification = $driver->verifyConfig($router);
            if ($verification->valid) {
                return new DeploymentSafetyResult(
                    success: true,
                    snapshotId: $snapshot?->id,
                    snapshotTaken: $snapshotTaken,
                    verificationPassed: true,
                    rolledBack: false,
                    message: 'Configuration applied and verified',
                    verification: $verification->toArray()
                );
            }

            $rolledBack = $snapshot && $rollbackOnFailedChecks
                ? $this->restoreSnapshot($driver, $router, $snapshot->config_text)
                : false;

            Log::warning('Post-deploy verification failed', [
                'router_id' => $router->id,
                'snapshot_id' => $snapshot?->id,
                'verification' => $verification->toArray(),
                'rolled_back' => $rolledBack,
            ]);

            return new DeploymentSafetyResult(
                success: false,
                snapshotId: $snapshot?->id,
                snapshotTaken: $snapshotTaken,
                verificationPassed: false,
                rolledBack: $rolledBack,
                message: 'Post-deploy verification failed',
                verification: $verification->toArray(),
                error: $verification->error ?? 'verification_failed'
            );
        } catch (\Throwable $e) {
            Log::error('Deployment safety pipeline failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return new DeploymentSafetyResult(
                success: false,
                snapshotId: null,
                snapshotTaken: false,
                verificationPassed: false,
                rolledBack: false,
                message: 'Deployment safety pipeline failed',
                error: $e->getMessage()
            );
        }
    }

    private function restoreSnapshot(RouterDriverInterface $driver, Router $router, string $snapshotConfig): bool
    {
        try {
            return $driver->restoreConfig($router, $snapshotConfig);
        } catch (\Throwable $e) {
            Log::error('Rollback to snapshot failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
