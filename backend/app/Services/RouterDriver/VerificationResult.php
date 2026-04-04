<?php

namespace App\Services\RouterDriver;

/**
 * Verification Result
 */
readonly class VerificationResult
{
    public function __construct(
        public bool $valid,
        public array $checks = [],
        public ?string $error = null,
        public array $details = []
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'checks' => $this->checks,
            'error' => $this->error,
            'details' => $this->details,
        ];
    }
}
