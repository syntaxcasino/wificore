<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class SafeRelativeTime
{
    public static function fromNow(DateTimeInterface|string|int|float|null $value, string $fallback = 'Unknown'): string
    {
        $date = self::normalize($value);
        if (! $date) {
            return $fallback;
        }

        $seconds = max(0, $date->diffInSeconds(now()));
        return self::formatSeconds($seconds) . ' ago';
    }

    public static function until(DateTimeInterface|string|int|float|null $value, string $fallback = 'Unlimited'): string
    {
        $date = self::normalize($value);
        if (! $date) {
            return $fallback;
        }

        $seconds = now()->diffInSeconds($date, false);
        if ($seconds <= 0) {
            return '0s';
        }

        return self::formatSeconds($seconds);
    }

    private static function normalize(DateTimeInterface|string|int|float|null $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function formatSeconds(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }
}
