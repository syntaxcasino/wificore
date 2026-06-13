<?php

namespace App\Jobs;

use App\Models\ProvisioningRun;
use App\Models\Router;
use App\Services\ProvisioningRunAuditService;
use App\Services\RouterDriver\DriverRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rollback Router Configuration Job
 *
 * Restores router to previous configuration snapshot or a conservative
 * compensating-action plan when enough audit data is available.
 */
class RollbackRouterConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Router $router;
    public string $config;
    public ?string $provisioningRunId;

    public function __construct(Router $router, string $config, ?string $provisioningRunId = null)
    {
        $this->router = $router;
        $this->config = $config;
        $this->provisioningRunId = $provisioningRunId;
    }

    public function handle(): void
    {
        try {
            Log::info('Starting configuration rollback', [
                'router_id' => $this->router->id,
                'router_name' => $this->router->name,
                'provisioning_run_id' => $this->provisioningRunId,
            ]);

            $driver = app(DriverRegistry::class)
                ->getDriverForRouter($this->router);

            $audit = app(ProvisioningRunAuditService::class);
            $run = $this->provisioningRunId ? ProvisioningRun::find($this->provisioningRunId) : null;

            if ($run) {
                $plan = $audit->buildRollbackPlan($run);
                if (($plan['actions'] ?? []) !== [] && ($plan['complete'] ?? false) === true) {
                    if ($this->executeCompensatingPlan($driver, $run, $plan['actions'], $audit)) {
                        Log::info('Compensating rollback plan completed successfully', [
                            'router_id' => $this->router->id,
                            'provisioning_run_id' => $this->provisioningRunId,
                            'actions' => count($plan['actions']),
                        ]);
                        return;
                    }

                    Log::warning('Compensating rollback plan failed, falling back to snapshot restore', [
                        'router_id' => $this->router->id,
                        'provisioning_run_id' => $this->provisioningRunId,
                        'actions' => count($plan['actions']),
                    ]);
                }
            }

            $success = $driver->restoreConfig($this->router, $this->config);

            if ($success) {
                Log::info('Configuration rollback successful', [
                    'router_id' => $this->router->id,
                    'provisioning_run_id' => $this->provisioningRunId,
                ]);
                $this->logRollbackOutcome(true, null);
            } else {
                Log::error('Configuration rollback failed', [
                    'router_id' => $this->router->id,
                    'provisioning_run_id' => $this->provisioningRunId,
                ]);
                $this->logRollbackOutcome(false, 'Failed to restore configuration');
                $this->fail('Failed to restore configuration');
            }
        } catch (\Exception $e) {
            Log::error('Exception during configuration rollback', [
                'router_id' => $this->router->id,
                'error' => $e->getMessage(),
                'provisioning_run_id' => $this->provisioningRunId,
            ]);
            $this->logRollbackOutcome(false, $e->getMessage());
            $this->fail($e);
        }
    }

    private function executeCompensatingPlan(
        mixed $driver,
        ProvisioningRun $run,
        array $actions,
        ProvisioningRunAuditService $audit,
    ): bool {
        foreach ($actions as $action) {
            $command = (string) ($action['command'] ?? '');
            if (trim($command) === '') {
                continue;
            }

            $audit->logStep($run, [
                'stage' => 'rollback',
                'action' => 'execute_compensating_action',
                'status' => 'running',
                'command' => $command,
                'command_payload' => $action,
                'response_payload' => [
                    'source_step_id' => $action['source_step_id'] ?? null,
                    'source_sequence' => $action['source_sequence'] ?? null,
                    'resource_id' => $action['resource_id'] ?? null,
                    'strategy' => 'compensating_actions',
                ],
                'is_terminal' => false,
                'started_at' => now(),
            ]);

            try {
                $result = $driver->executeCommand($this->router, $command);
                $resultPayload = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
                $success = (bool) ($resultPayload['success'] ?? false);

                $audit->logStep($run, [
                    'stage' => 'rollback',
                    'action' => 'execute_compensating_action',
                    'status' => $success ? 'completed' : 'failed',
                    'command' => $command,
                    'command_payload' => $action,
                    'response_payload' => $resultPayload,
                    'error_message' => $success ? null : ($resultPayload['error'] ?? 'Failed to execute compensating rollback command'),
                    'is_terminal' => ! $success,
                    'completed_at' => now(),
                ]);

                if (! $success) {
                    $audit->updateRun(
                        $run,
                        ProvisioningRun::STATUS_FAILED,
                        (int) $run->progress,
                        'rollback_failed',
                        (string) ($resultPayload['error'] ?? 'Failed to execute compensating rollback command'),
                        [
                            'rollback_strategy' => 'compensating_actions',
                            'rollback_actions_completed' => 0,
                            'rollback_actions_total' => count($actions),
                        ]
                    );

                    return false;
                }
            } catch (\Throwable $e) {
                $audit->logStep($run, [
                    'stage' => 'rollback',
                    'action' => 'execute_compensating_action',
                    'status' => 'failed',
                    'command' => $command,
                    'command_payload' => $action,
                    'error_message' => $e->getMessage(),
                    'response_payload' => [
                        'source_step_id' => $action['source_step_id'] ?? null,
                        'source_sequence' => $action['source_sequence'] ?? null,
                        'resource_id' => $action['resource_id'] ?? null,
                        'strategy' => 'compensating_actions',
                    ],
                    'is_terminal' => true,
                    'completed_at' => now(),
                ]);

                $audit->updateRun(
                    $run,
                    ProvisioningRun::STATUS_FAILED,
                    (int) $run->progress,
                    'rollback_failed',
                    $e->getMessage(),
                    [
                        'rollback_strategy' => 'compensating_actions',
                        'rollback_actions_completed' => 0,
                        'rollback_actions_total' => count($actions),
                    ]
                );

                return false;
            }
        }

        $audit->updateRun(
            $run,
            ProvisioningRun::STATUS_ROLLED_BACK,
            100,
            'rolled_back',
            null,
            [
                'rollback_strategy' => 'compensating_actions',
                'rollback_actions_completed' => count($actions),
                'rollback_actions_total' => count($actions),
            ]
        );

        return true;
    }

    private function logRollbackOutcome(bool $success, ?string $errorMessage): void
    {
        if (! $this->provisioningRunId) {
            return;
        }

        $run = ProvisioningRun::find($this->provisioningRunId);
        if (! $run) {
            return;
        }

        $audit = app(ProvisioningRunAuditService::class);
        $audit->logStep($run, [
            'stage' => 'rollback',
            'action' => 'execute_rollback',
            'status' => $success ? 'completed' : 'failed',
            'response_payload' => [
                'router_id' => $this->router->id,
                'result' => $success ? 'rolled_back' : 'rollback_failed',
                'strategy' => 'snapshot_restore',
            ],
            'error_message' => $errorMessage,
            'is_terminal' => true,
            'completed_at' => now(),
        ]);

        if ($success) {
            $audit->updateRun(
                $run,
                ProvisioningRun::STATUS_ROLLED_BACK,
                100,
                'rolled_back',
                null,
                [
                    'rollback_strategy' => 'snapshot_restore',
                ]
            );
            return;
        }

        $audit->updateRun(
            $run,
            ProvisioningRun::STATUS_FAILED,
            (int) $run->progress,
            'rollback_failed',
            $errorMessage,
            [
                'rollback_strategy' => 'snapshot_restore',
            ]
        );
    }
}
