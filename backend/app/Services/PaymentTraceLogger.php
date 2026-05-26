<?php

namespace App\Services;

use App\Models\SystemPaymentSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentTraceLogger
{
    public const MODE_STDOUT = 'stdout';
    public const MODE_PERSISTENT = 'persistent';
    public const MODE_BOTH = 'both';

    private const CACHE_KEY = 'system_payment_trace_mode';
    private const TRACE_FILE = 'payment_trace.log';

    public function mode(): string
    {
        $mode = Cache::remember(self::CACHE_KEY, 30, static function (): string {
            return SystemPaymentSetting::getActive()?->payment_trace_mode ?? self::MODE_STDOUT;
        });

        return in_array($mode, [self::MODE_STDOUT, self::MODE_PERSISTENT, self::MODE_BOTH], true)
            ? $mode
            : self::MODE_STDOUT;
    }

    public function clearModeCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function log(string $stage, array $details = [], string $level = 'info'): void
    {
        $sanitized = $this->sanitizeLogData($details);
        $mode = $this->mode();
        $record = [
            'timestamp' => now()->toIso8601String(),
            'stage' => $stage,
            'details' => $sanitized,
            'mode' => $mode,
        ];

        if (in_array($mode, [self::MODE_PERSISTENT, self::MODE_BOTH], true)) {
            try {
                file_put_contents(
                    storage_path('logs/' . self::TRACE_FILE),
                    json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                Log::debug('Payment trace file write failed', [
                    'stage' => $stage,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (in_array($mode, [self::MODE_STDOUT, self::MODE_BOTH], true)) {
            try {
                Log::channel('stderr')->$level($stage, $sanitized);
            } catch (\Throwable $e) {
                Log::debug('Payment trace stdout write failed', [
                    'stage' => $stage,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['password', 'passkey', 'secret', 'auth', 'token'];
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '*****';
            }
        });

        return $data;
    }
}
