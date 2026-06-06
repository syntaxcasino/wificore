<?php

namespace App\Services\Deployment;

use Carbon\CarbonInterface;

readonly class RouterComplianceReport
{
    public function __construct(
        public string $routerId,
        public ?string $tenantId,
        public int $score,
        public string $grade,
        public string $status,
        public array $checks,
        public array $missingControls,
        public array $passedControls,
        public string $summary,
        public ?string $sourceSnapshotId = null,
        public ?CarbonInterface $evaluatedAt = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'router_id' => $this->routerId,
            'tenant_id' => $this->tenantId,
            'score' => $this->score,
            'grade' => $this->grade,
            'status' => $this->status,
            'checks' => $this->checks,
            'missing_controls' => $this->missingControls,
            'passed_controls' => $this->passedControls,
            'summary' => $this->summary,
            'source_snapshot_id' => $this->sourceSnapshotId,
            'evaluated_at' => $this->evaluatedAt?->toIso8601String(),
        ];
    }
}
