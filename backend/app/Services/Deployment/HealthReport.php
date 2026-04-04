<?php

namespace App\Services\Deployment;

/**
 * Health Report for Canary Deployment
 */
readonly class HealthReport
{
    public function __construct(
        public int $deploymentId,
        public float $healthScore,
        public int $successCount,
        public int $failureCount,
        public array $results,
        public bool $canPromote,
        public bool $shouldRollback
    ) {}

    public function toArray(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
            'health_score' => $this->healthScore,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'results' => $this->results,
            'can_promote' => $this->canPromote,
            'should_rollback' => $this->shouldRollback,
        ];
    }
}
