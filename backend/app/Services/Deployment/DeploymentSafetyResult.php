<?php

namespace App\Services\Deployment;

readonly class DeploymentSafetyResult
{
    public function __construct(
        public bool $success,
        public ?string $snapshotId = null,
        public bool $snapshotTaken = false,
        public bool $verificationPassed = false,
        public bool $rolledBack = false,
        public ?string $message = null,
        public array $verification = [],
        public array $snapshot = [],
        public ?string $error = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'snapshot_id' => $this->snapshotId,
            'snapshot_taken' => $this->snapshotTaken,
            'verification_passed' => $this->verificationPassed,
            'rolled_back' => $this->rolledBack,
            'message' => $this->message,
            'verification' => $this->verification,
            'snapshot' => $this->snapshot,
            'error' => $this->error,
        ];
    }
}
