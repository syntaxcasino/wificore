<?php

namespace App\Services\Deployment;

use App\Models\Router;
use App\Models\CanaryDeployment;
use App\Services\RouterDriver\DriverRegistry;
use App\Services\RouterDriver\RouterDriverInterface;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Canary Deployment Service
 * 
 * Implements gradual rollout of configuration changes with automatic
 * health monitoring and rollback capabilities.
 */
class CanaryDeploymentService
{
    private DriverRegistry $driverRegistry;
    private ConfigDriftDetector $driftDetector;

    public function __construct(
        ?DriverRegistry $driverRegistry = null,
        ?ConfigDriftDetector $driftDetector = null
    ) {
        $this->driverRegistry = $driverRegistry ?? app(DriverRegistry::class);
        $this->driftDetector = $driftDetector ?? app(ConfigDriftDetector::class);
    }

    /**
     * Start a new canary deployment
     * 
     * @param array $routerIds Router IDs to include in deployment
     * @param string $config Configuration to deploy
     * @param string $configVersion Version identifier for this config
     * @param int $percentage Percentage of routers for canary (10-50)
     * @param int $healthCheckInterval Seconds between health checks
     * @return CanaryDeployment The created deployment
     */
    public function startDeployment(
        array $routerIds,
        string $config,
        string $configVersion,
        int $percentage = 10,
        int $healthCheckInterval = 60
    ): CanaryDeployment {
        try {
            DB::beginTransaction();

            // Validate percentage
            $percentage = max(10, min(50, $percentage));

            // Select canary subset using stratified sampling
            $canaryRouters = $this->selectCanarySubset($routerIds, $percentage);
            $remainingRouters = array_diff($routerIds, $canaryRouters);

            // Create deployment record
            $deployment = CanaryDeployment::create([
                'config_version' => $configVersion,
                'config_hash' => hash('sha256', $config),
                'total_routers' => count($routerIds),
                'canary_count' => count($canaryRouters),
                'canary_routers' => $canaryRouters,
                'remaining_routers' => $remainingRouters,
                'percentage' => $percentage,
                'status' => 'canary_running',
                'health_check_interval' => $healthCheckInterval,
                'config_content' => $config, // Encrypted at rest
                'started_at' => now(),
            ]);

            // Create snapshots for drift detection
            foreach ($routerIds as $routerId) {
                $this->driftDetector->snapshotConfiguration(
                    Router::find($routerId)
                );
            }

            DB::commit();

            // Dispatch canary deployment jobs
            $this->dispatchCanaryJobs($deployment, $config);

            Log::info('Canary deployment started', [
                'deployment_id' => $deployment->id,
                'config_version' => $configVersion,
                'canary_count' => count($canaryRouters),
                'total_count' => count($routerIds),
            ]);

            return $deployment;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to start canary deployment', [
                'error' => $e->getMessage(),
                'config_version' => $configVersion,
            ]);

            throw $e;
        }
    }

    /**
     * Check health of canary deployment
     * 
     * @param CanaryDeployment $deployment
     * @return HealthReport Current health status
     */
    public function checkCanaryHealth(CanaryDeployment $deployment): HealthReport
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($deployment->canary_routers as $routerId) {
            $router = Router::find($routerId);
            if (!$router) {
                $failureCount++;
                continue;
            }

            $driver = $this->driverRegistry->getDriverForRouter($router);
            
            // Check connectivity
            $connectivity = $driver->checkConnectivity($router);
            
            // Verify configuration
            $verification = $driver->verifyConfig($router);
            
            // Check for drift
            $drift = $this->driftDetector->detectDrift($router);

            $isHealthy = $connectivity->reachable && $verification->valid && !$drift->hasDrift();

            $results[$routerId] = [
                'healthy' => $isHealthy,
                'connectivity' => $connectivity->toArray(),
                'verification' => $verification->toArray(),
                'drift' => $drift->toArray(),
            ];

            if ($isHealthy) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        $healthScore = $deployment->canary_count > 0 
            ? ($successCount / $deployment->canary_count) * 100 
            : 0;

        // Update deployment health score
        $deployment->update([
            'health_score' => $healthScore,
            'last_health_check' => now(),
        ]);

        return new HealthReport(
            deploymentId: $deployment->id,
            healthScore: $healthScore,
            successCount: $successCount,
            failureCount: $failureCount,
            results: $results,
            canPromote: $healthScore >= 95,
            shouldRollback: $healthScore < 80
        );
    }

    /**
     * Promote canary to full deployment
     * 
     * @param CanaryDeployment $deployment
     * @return bool True if promotion started
     */
    public function promoteToFullDeployment(CanaryDeployment $deployment): bool
    {
        try {
            if ($deployment->status !== 'canary_running') {
                throw new \InvalidArgumentException(
                    "Cannot promote deployment with status: {$deployment->status}"
                );
            }

            // Decrypt config
            $config = $deployment->getDecryptedConfig();

            // Update status
            $deployment->update([
                'status' => 'promoting',
                'promoted_at' => now(),
            ]);

            // Dispatch jobs for remaining routers
            $this->dispatchRemainingJobs($deployment, $config);

            Log::info('Canary deployment promoted to full', [
                'deployment_id' => $deployment->id,
                'remaining_routers' => count($deployment->remaining_routers),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to promote canary deployment', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Rollback canary deployment
     * 
     * @param CanaryDeployment $deployment
     * @return bool True if rollback started
     */
    public function rollback(CanaryDeployment $deployment): bool
    {
        try {
            // Update status
            $deployment->update([
                'status' => 'rolling_back',
                'rolled_back_at' => now(),
            ]);

            // Get previous configuration snapshots
            foreach ($deployment->canary_routers as $routerId) {
                $router = Router::find($routerId);
                if (!$router) {
                    continue;
                }

                $snapshot = $this->driftDetector->getLatestSnapshot($router);
                if ($snapshot) {
                    // Dispatch rollback job
                    dispatch(new \App\Jobs\RollbackRouterConfigJob(
                        $router,
                        $snapshot->config_content
                    ));
                }
            }

            Log::info('Canary deployment rollback initiated', [
                'deployment_id' => $deployment->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to rollback canary deployment', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Auto-promote or auto-rollback based on health score
     * 
     * @param CanaryDeployment $deployment
     * @return string Action taken
     */
    public function autoEvaluate(CanaryDeployment $deployment): string
    {
        $health = $this->checkCanaryHealth($deployment);

        if ($health->shouldRollback) {
            $this->rollback($deployment);
            $deployment->update(['status' => 'auto_rolled_back']);
            return 'rollback';
        }

        if ($health->canPromote) {
            $this->promoteToFullDeployment($deployment);
            return 'promote';
        }

        return 'continue_monitoring';
    }

    /**
     * Select canary subset using stratified sampling
     * 
     * Ensures representation across different router models and locations.
     */
    private function selectCanarySubset(array $routerIds, int $percentage): array
    {
        $routers = Router::whereIn('id', $routerIds)->get();
        
        // Group by model for stratification
        $byModel = $routers->groupBy('model');
        
        $canarySet = [];
        $targetCount = (int) ceil(count($routerIds) * ($percentage / 100));

        // Select from each model group proportionally
        foreach ($byModel as $model => $modelRouters) {
            $modelCount = $modelRouters->count();
            $modelCanaryCount = max(1, (int) round($targetCount * ($modelCount / count($routerIds))));
            
            $selected = $modelRouters->random(min($modelCanaryCount, $modelCount))
                ->pluck('id')
                ->toArray();
            
            $canarySet = array_merge($canarySet, $selected);
        }

        // Remove duplicates and limit to target
        $canarySet = array_unique($canarySet);
        
        if (count($canarySet) > $targetCount) {
            shuffle($canarySet);
            $canarySet = array_slice($canarySet, 0, $targetCount);
        }

        return $canarySet;
    }

    /**
     * Dispatch deployment jobs for canary routers
     */
    private function dispatchCanaryJobs(CanaryDeployment $deployment, string $config): void
    {
        $jobs = [];
        
        foreach ($deployment->canary_routers as $routerId) {
            $router = Router::find($routerId);
            if (!$router) {
                continue;
            }

            $jobs[] = new \App\Jobs\CanaryDeployJob(
                $deployment,
                $router,
                $config
            );
        }

        if (!empty($jobs)) {
            Bus::batch($jobs)
                ->name("canary-{$deployment->id}")
                ->then(function (Batch $batch) use ($deployment) {
                    // All canary jobs completed, start health monitoring
                    $this->startHealthMonitoring($deployment);
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($deployment) {
                    $deployment->update(['status' => 'failed']);
                    Log::error('Canary deployment batch failed', [
                        'deployment_id' => $deployment->id,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->dispatch();
        }
    }

    /**
     * Dispatch jobs for remaining routers after promotion
     */
    private function dispatchRemainingJobs(CanaryDeployment $deployment, string $config): void
    {
        $jobs = [];

        foreach ($deployment->remaining_routers as $routerId) {
            $router = Router::find($routerId);
            if (!$router) {
                continue;
            }

            $jobs[] = new \App\Jobs\DeployRouterConfigJob(
                $router,
                $config,
                $deployment->config_version
            );
        }

        if (!empty($jobs)) {
            Bus::batch($jobs)
                ->name("promote-{$deployment->id}")
                ->then(function (Batch $batch) use ($deployment) {
                    $deployment->update(['status' => 'completed', 'completed_at' => now()]);
                })
                ->dispatch();
        } else {
            $deployment->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    /**
     * Start periodic health monitoring
     */
    private function startHealthMonitoring(CanaryDeployment $deployment): void
    {
        // Schedule first health check
        dispatch(new \App\Jobs\CanaryHealthCheckJob($deployment))
            ->delay(now()->addSeconds($deployment->health_check_interval));
    }
}
