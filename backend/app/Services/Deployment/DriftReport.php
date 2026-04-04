<?php

namespace App\Services\Deployment;

use Carbon\Carbon;

/**
 * Drift Report
 */
readonly class DriftReport
{
    public function __construct(
        public int $routerId,
        public bool $hasDrift,
        public ?int $baselineSnapshotId = null,
        public ?string $currentHash = null,
        public array $differences = [],
        public string $severity = 'none',
        public ?Carbon $detectedAt = null,
        public ?string $error = null
    ) {}

    public function toArray(): array
    {
        return [
            'router_id' => $this->routerId,
            'has_drift' => $this->hasDrift,
            'baseline_snapshot_id' => $this->baselineSnapshotId,
            'current_hash' => $this->currentHash,
            'differences' => $this->differences,
            'differences_count' => count($this->differences),
            'severity' => $this->severity,
            'detected_at' => $this->detectedAt?->toIso8601String(),
            'error' => $this->error,
        ];
    }

    /**
     * Check if drift requires immediate attention
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical' || $this->severity === 'high';
    }
}
