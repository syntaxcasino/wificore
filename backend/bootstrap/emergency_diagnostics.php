<?php

if (! function_exists('wificore_emergency_log')) {
    function wificore_emergency_log(string $level, string $message, array $context = []): void
    {
        static $inProgress = false;

        if ($inProgress) {
            return;
        }

        $inProgress = true;

        try {
            $logDir = __DIR__ . '/../storage/logs';
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }

            $payload = [
                'ts' => gmdate('c'),
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];

            $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                $line = sprintf(
                    '{"ts":"%s","level":"%s","message":"%s","context":"json_encode_failed"}',
                    gmdate('c'),
                    addslashes($level),
                    addslashes($message)
                );
            }

            @file_put_contents($logDir . '/bootstrap-fatal.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            @error_log('[wificore-bootstrap] ' . $line);
        } catch (Throwable) {
            // Intentionally swallow all logging failures.
        } finally {
            $inProgress = false;
        }
    }
}

if (! defined('WIFICORE_EMERGENCY_DIAGNOSTICS_REGISTERED')) {
    define('WIFICORE_EMERGENCY_DIAGNOSTICS_REGISTERED', true);

    set_exception_handler(function (Throwable $e): void {
        $context = [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'memory_limit' => ini_get('memory_limit'),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'sapi' => PHP_SAPI,
        ];

        if (PHP_SAPI === 'cli') {
            global $argv;
            $context['argv'] = $argv ?? [];
        } else {
            $context['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
            $context['uri'] = $_SERVER['REQUEST_URI'] ?? null;
            $context['host'] = $_SERVER['HTTP_HOST'] ?? null;
            $context['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        wificore_emergency_log('critical', 'Uncaught bootstrap exception', $context);
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if (! is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR];
        if (! in_array($error['type'] ?? null, $fatalTypes, true)) {
            return;
        }

        $context = [
            'type' => $error['type'] ?? null,
            'message' => $error['message'] ?? null,
            'file' => $error['file'] ?? null,
            'line' => $error['line'] ?? null,
            'memory_limit' => ini_get('memory_limit'),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'sapi' => PHP_SAPI,
        ];

        if (PHP_SAPI === 'cli') {
            global $argv;
            $context['argv'] = $argv ?? [];
        } else {
            $context['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
            $context['uri'] = $_SERVER['REQUEST_URI'] ?? null;
            $context['host'] = $_SERVER['HTTP_HOST'] ?? null;
            $context['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        wificore_emergency_log('critical', 'Fatal bootstrap shutdown error', $context);
    });
}
