<?php

namespace App\Services\RADIUS;

/**
 * Data Transfer Object for VLAN assignment results
 */
readonly class VlanAssignmentResult
{
    /**
     * @param bool $success Whether the assignment succeeded
     * @param string $message Human-readable message
     * @param int|null $vlanId Assigned VLAN ID
     * @param string|null $effective When the change takes effect: 'immediate' or 'next_session'
     * @param bool $coaUsed Whether CoA was used for dynamic change
     */
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $vlanId = null,
        public ?string $effective = null,
        public bool $coaUsed = false
    ) {}

    /**
     * Convert result to array for JSON responses
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'vlan_id' => $this->vlanId,
            'effective' => $this->effective,
            'coa_used' => $this->coaUsed,
        ];
    }

    /**
     * Check if assignment was successful
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Check if change is immediate (via CoA)
     */
    public function isImmediate(): bool
    {
        return $this->effective === 'immediate';
    }
}
