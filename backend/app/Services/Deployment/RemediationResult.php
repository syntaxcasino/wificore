<?php

namespace App\Services\Deployment;

/**
 * Remediation Result
 */
readonly class RemediationResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $restoredFromSnapshotId = null,
        public bool $verificationPassed = false,
        public bool $requiresManualApproval = false
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'restored_from_snapshot_id' => $this->restoredFromSnapshotId,
            'verification_passed' => $this->verificationPassed,
            'requires_manual_approval' => $this->requiresManualApproval,
        ];
    }
}
