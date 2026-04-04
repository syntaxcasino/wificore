<?php

namespace App\Services\RouterDriver;

/**
 * Command Result
 */
readonly class CommandResult
{
    public function __construct(
        public bool $success,
        public string $output,
        public ?string $error = null,
        public int $exitCode = 0
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'error' => $this->error,
            'exit_code' => $this->exitCode,
        ];
    }
}
