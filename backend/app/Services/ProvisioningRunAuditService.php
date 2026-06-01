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

    public function logCommandResults(ProvisioningRun $run, array $results, array $context = []): array
    {
        $loggedSteps = [];

        foreach (array_values($results) as $index => $result) {
            if (! is_array($result)) {
                continue;
            }

            $loggedSteps[] = $this->logStep($run, array_merge($context, $this->normalizeCommandResult($result, $index)));
        }

        return $loggedSteps;
    }

    /**
     * Build a conservative rollback plan from completed provisioning steps.
     *
     * The plan only includes reverse operations we can execute safely:
     * completed add operations that expose a resource identifier.
     */
    public function buildRollbackPlan(ProvisioningRun $run): array
    {
        $steps = ProvisioningStep::query()
            ->where('provisioning_run_id', $run->id)
            ->orderByDesc('sequence')
            ->get();

        return $this->buildRollbackPlanFromSteps($steps);
    }

    /**
     * Build a rollback plan from an iterable of step-like records.
     *
     * @param iterable<mixed> $steps
     */
    public function buildRollbackPlanFromSteps(iterable $steps): array
    {
        $actions = [];
        $incompleteSteps = [];

        foreach ($steps as $step) {
            $stage = (string) $this->stepValue($step, 'stage', '');
            if ($stage === 'rollback') {
                continue;
            }

            $status = (string) $this->stepValue($step, 'status', '');
            if ($status !== ProvisioningStep::STATUS_COMPLETED) {
                continue;
            }

            $command = trim((string) $this->stepValue($step, 'command', ''));
            if ($command === '') {
                continue;
            }

            $verb = $this->extractCommandVerb($command);
            if ($verb === null) {
                continue;
            }

            if ($verb === 'add') {
                $action = $this->buildCompensatingAction($step, $command);
                if ($action !== null) {
                    $actions[] = $action;
                    continue;
                }

                $incompleteSteps[] = [
                    'step_id' => $this->stepValue($step, 'id'),
                    'sequence' => $this->stepValue($step, 'sequence'),
                    'reason' => 'missing_resource_identifier',
                    'command' => $command,
                ];
                continue;
            }

            if (in_array($verb, ['set', 'enable', 'disable', 'move', 'reset'], true)) {
                $incompleteSteps[] = [
                    'step_id' => $this->stepValue($step, 'id'),
                    'sequence' => $this->stepValue($step, 'sequence'),
                    'reason' => 'non_reversible_mutation',
                    'command' => $command,
                ];
            }
        }

        return [
            'complete' => $incompleteSteps === [],
            'actions' => $actions,
            'incomplete_steps' => $incompleteSteps,
            'strategy' => $actions !== [] ? 'compensating_actions' : 'snapshot_restore',
        ];
    }

    private function normalizeCommandResult(array $result, int $index): array
    {
        $trapMessage = $this->extractTrapMessage($result);
        $status = $this->normalizeCommandStatus($result, $trapMessage);
        $command = $result['command'] ?? $result['cmd'] ?? $result['path'] ?? $result['name'] ?? ('command_' . ($index + 1));
        $message = $result['message'] ?? $result['response_message'] ?? null;
        if ($trapMessage === null && $this->isFailureStatus($result)) {
            $trapMessage = is_string($message) && trim($message) !== '' ? trim($message) : null;
        }

        return [
            'stage' => (string) ($result['stage'] ?? $result['step'] ?? $result['phase'] ?? 'command_result'),
            'action' => (string) ($result['action'] ?? 'execute_command'),
            'status' => $status,
            'command' => is_string($command) ? $command : json_encode($command),
            'command_payload' => $result['command_payload'] ?? null,
            'response_payload' => $result,
            'trap_message' => $trapMessage,
            'error_message' => $result['error_message'] ?? $result['error'] ?? $trapMessage,
            'is_terminal' => in_array($status, [ProvisioningStep::STATUS_COMPLETED, ProvisioningStep::STATUS_FAILED], true),
            'duration_ms' => $result['duration_ms'] ?? null,
            'started_at' => isset($result['started_at']) ? $result['started_at'] : now(),
            'completed_at' => $result['completed_at'] ?? now(),
        ];
    }

    private function normalizeCommandStatus(array $result, ?string $trapMessage): string
    {
        if ($trapMessage !== null || isset($result['trap']) || isset($result['fatal']) || isset($result['error'])) {
            return ProvisioningStep::STATUS_FAILED;
        }

        if (array_key_exists('success', $result)) {
            return filter_var($result['success'], FILTER_VALIDATE_BOOL)
                ? ProvisioningStep::STATUS_COMPLETED
                : ProvisioningStep::STATUS_FAILED;
        }

        $status = strtolower(trim((string) ($result['status'] ?? $result['result'] ?? 'completed')));

        if (in_array($status, ['ok', 'done', 'success', 'completed', 'complete'], true)) {
            return ProvisioningStep::STATUS_COMPLETED;
        }

        if (in_array($status, ['trap', 'fatal', 'failed', 'error'], true)) {
            return ProvisioningStep::STATUS_FAILED;
        }

        return ProvisioningStep::STATUS_RUNNING;
    }

    private function extractTrapMessage(array $result): ?string
    {
        foreach (['trap_message', 'trap', 'fatal', 'error_message', 'error'] as $key) {
            if (! array_key_exists($key, $result)) {
                continue;
            }

            $value = $result[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value) && isset($value['message']) && is_string($value['message']) && trim($value['message']) !== '') {
                return trim($value['message']);
            }
        }

        if (isset($result['response']) && is_array($result['response'])) {
            return $this->extractTrapMessage($result['response']);
        }

        return null;
    }

    private function isFailureStatus(array $result): bool
    {
        $status = strtolower(trim((string) ($result['status'] ?? $result['result'] ?? '')));

        return in_array($status, ['trap', 'fatal', 'failed', 'error'], true);
    }

    private function nextSequence(ProvisioningRun $run): int
    {
        return (int) ProvisioningStep::where('provisioning_run_id', $run->id)->max('sequence');
    }

    private function buildCompensatingAction(mixed $step, string $command): ?array
    {
        $path = $this->extractCommandPath($command);
        if ($path === null) {
            return null;
        }

        $resourceId = $this->extractResourceId($this->stepValue($step, 'response_payload'));
        if ($resourceId === null) {
            return null;
        }

        $removePath = preg_replace('/\badd$/', 'remove', $path);
        if (! is_string($removePath) || $removePath === $path) {
            return null;
        }

        return [
            'source_step_id' => $this->stepValue($step, 'id'),
            'source_sequence' => $this->stepValue($step, 'sequence'),
            'stage' => $this->stepValue($step, 'stage'),
            'action' => $this->stepValue($step, 'action'),
            'source_command' => $command,
            'command' => trim($removePath) . ' numbers=' . $resourceId,
            'resource_id' => $resourceId,
        ];
    }

    private function extractCommandPath(string $command): ?string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $pathTokens = [];

        foreach ($tokens as $token) {
            if ($token === ''
                || str_contains($token, '=')
                || str_starts_with($token, '?')
                || str_starts_with($token, '[')
                || str_starts_with($token, '(')
                || str_starts_with($token, '"')
                || str_starts_with($token, "'")
            ) {
                break;
            }

            $pathTokens[] = $token;
        }

        if ($pathTokens === []) {
            return null;
        }

        return implode(' ', $pathTokens);
    }

    private function extractCommandVerb(string $command): ?string
    {
        $path = $this->extractCommandPath($command);
        if ($path === null) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($path)) ?: [];
        $verb = end($tokens);

        return is_string($verb) && $verb !== '' ? strtolower($verb) : null;
    }

    private function extractResourceId(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['.id', 'id', 'numbers', 'ret', 'resource_id'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        foreach (['response', 'result', 'data', 'command_result'] as $key) {
            if (isset($payload[$key])) {
                $nested = $this->extractResourceId($payload[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $nested = $this->extractResourceId($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function stepValue(mixed $step, string $key, mixed $default = null): mixed
    {
        if (is_array($step)) {
            return $step[$key] ?? $default;
        }

        if (is_object($step)) {
            return $step->{$key} ?? $default;
        }

        return $default;
    }
}

