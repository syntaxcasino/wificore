<?php

namespace App\Services\RADIUS;

/**
 * Data Transfer Object for CoA operation results
 */
readonly class CoAResult
{
    /**
     * @param bool $success Whether the operation succeeded
     * @param string $message Human-readable result message
     * @param int|null $errorCode RADIUS error code if failed
     * @param array $attributes Additional response attributes
     */
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $errorCode = null,
        public array $attributes = []
    ) {}

    /**
     * Convert result to array for JSON responses
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * Check if operation was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }
}
