<?php

namespace App\Services\RouterDriver;

/**
 * Resource Usage
 */
readonly class ResourceUsage
{
    public function __construct(
        public float $cpuLoad,
        public int $memoryUsed,
        public int $memoryTotal,
        public float $memoryUsagePercent,
        public ?int $diskUsed = null,
        public ?int $diskTotal = null,
        public ?float $diskUsagePercent = null
    ) {}

    public function toArray(): array
    {
        return [
            'cpu_load' => $this->cpuLoad,
            'memory_used' => $this->memoryUsed,
            'memory_total' => $this->memoryTotal,
            'memory_usage_percent' => $this->memoryUsagePercent,
            'disk_used' => $this->diskUsed,
            'disk_total' => $this->diskTotal,
            'disk_usage_percent' => $this->diskUsagePercent,
        ];
    }
}
