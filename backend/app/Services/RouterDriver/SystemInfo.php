<?php

namespace App\Services\RouterDriver;

/**
 * System Information
 */
readonly class SystemInfo
{
    public function __construct(
        public string $version,
        public string $architecture,
        public string $boardName,
        public int $cpuCount,
        public int $memoryTotal,
        public int $memoryFree,
        public float $cpuLoad,
        public ?float $temperature = null,
        public string $uptime
    ) {}

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'architecture' => $this->architecture,
            'board_name' => $this->boardName,
            'cpu_count' => $this->cpuCount,
            'memory_total' => $this->memoryTotal,
            'memory_free' => $this->memoryFree,
            'cpu_load' => $this->cpuLoad,
            'temperature' => $this->temperature,
            'uptime' => $this->uptime,
        ];
    }
}
