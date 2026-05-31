<?php

namespace App\Services;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\Router;
use App\Models\RouterTask;

class ProvisioningRunAuditService
{
    public function startRun(Router $router, ?RouterTask $task = null, array $context = []): ProvisioningRun
    {
        return ProvisioningRun::create([
            'tenant_id' => $context['tenant_id'] ?? $task?->tenant_id,
            'router_id' => $router->id,
            'router_task_id' => $task?->id,
            'triggered_by_user_id' => $context['triggered_by_user_id'] ?? $task?->user_id,
            'source' => $context['source'] ?? 'router_task',
            'mode' => $context['mode'] ?? 'deploy',
            'status' => ProvisioningRun::STATUS_RUNNING,
            'progress' => (int) ($context['progress'] ?? 0),
            'current_stage' => $context['current_stage'] ?? 'queued',
            'metadata' => $context['metadata'] ?? null,
            'started_at' => now(),
        ]);
    }

    public function updateRun(
        ProvisioningRun $run,
        string $status,
        ?int $progress = null,
        ?string $stage = null,
        ?string $errorMessage = null,
        ?array $metadata = null,
    ): ProvisioningRun {
        $updateData = [
            'status' => $status,
        ];

        if ($progress !== null) {
            $updateData['progress'] = max(0, min(100, $progress));
        }

        if ($stage !== null) {
            $updateData['current_stage'] = $stage;
        }

        if ($errorMessage !== null) {
            $updateData['error_message'] = $errorMessage;
        }

        if ($metadata !== null) {
            $base = is_array($run->metadata) ? $run->metadata : [];
            $updateData['metadata'] = array_merge($base, $metadata);
        }

        if (in_array($status, [ProvisioningRun::STATUS_COMPLETED, ProvisioningRun::STATUS_FAILED, ProvisioningRun::STATUS_ROLLED_BACK], true)) {
            $completedAt = now();
            $updateData['completed_at'] = $completedAt;
            $startedAt = $run->started_at ?? $run->created_at;
            if ($startedAt) {
                $updateData['duration_ms'] = (int) max(0, $startedAt->diffInMilliseconds($completedAt));
            }
        }

        $run->forceFill($updateData)->save();

        return $run->refresh();
    }

    public function logStep(ProvisioningRun $run, array $data): ProvisioningStep
    {
        $sequence = (int) ($data['sequence'] ?? ($this->nextSequence($run) + 1));

        return ProvisioningStep::create([
            'provisioning_run_id' => $run->id,
            'tenant_id' => $data['tenant_id'] ?? $run->tenant_id,
            'router_id' => $data['router_id'] ?? $run->router_id,
            'router_task_id' => $data['router_task_id'] ?? $run->router_task_id,
            'sequence' => $sequence,
            'stage' => $data['stage'] ?? 'unknown',
            'action' => $data['action'] ?? 'unspecified',
            'status' => $data['status'] ?? ProvisioningStep::STATUS_RUNNING,
            'command' => $data['command'] ?? null,
            'command_payload' => $data['command_payload'] ?? null,
            'response_payload' => $data['response_payload'] ?? null,
            'trap_message' => $data['trap_message'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'is_terminal' => (bool) ($data['is_terminal'] ?? false),
            'duration_ms' => $data['duration_ms'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'completed_at' => $data['completed_at'] ?? null,
        ]);
    }

    private function nextSequence(ProvisioningRun $run): int
    {
        return (int) ProvisioningStep::where('provisioning_run_id', $run->id)->max('sequence');
    }
}

