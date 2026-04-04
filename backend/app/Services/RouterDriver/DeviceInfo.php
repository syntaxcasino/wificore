<?php

namespace App\Services\RouterDriver;

/**
 * Device Information
 * 
 * Contains detected device details.
 */
readonly class DeviceInfo
{
    public function __construct(
        public string $vendor,
        public string $model,
        public string $firmwareVersion,
        public string $serialNumber,
        public ?string $hardwareVersion = null,
        public ?string $boardName = null,
        public ?string $identity = null,
        public ?string $uptime = null
    ) {}

    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'model' => $this->model,
            'firmware_version' => $this->firmwareVersion,
            'serial_number' => $this->serialNumber,
            'hardware_version' => $this->hardwareVersion,
            'board_name' => $this->boardName,
            'identity' => $this->identity,
            'uptime' => $this->uptime,
        ];
    }
}
