<?php

namespace App\Services\RouterDriver;

/**
 * Connection Status
 */
readonly class ConnectionStatus
{
    public function __construct(
        public bool $reachable,
        public int $latencyMs,
        public ?string $error = null,
        public ?string $connectionMethod = null
    ) {}

    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            'connection_method' => $this->connectionMethod,
        ];
    }
}
