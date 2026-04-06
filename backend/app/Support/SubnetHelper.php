<?php

namespace App\Support;

class SubnetHelper
{
    public static function normalize(?string $value, string $default = '10.0.0.0/8'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }

        if (str_contains($value, '-')) {
            return self::fromRange($value) ?? $default;
        }

        if (!str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP)
                ? $value . '/32'
                : $default;
        }

        [$ip, $mask] = explode('/', $value, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $default;
        }

        if (is_numeric($mask)) {
            $prefix = (int) $mask;
            if ($prefix < 0 || $prefix > 32) {
                $prefix = 32;
            }
            return sprintf('%s/%d', $ip, $prefix);
        }

        if (filter_var($mask, FILTER_VALIDATE_IP)) {
            $prefix = self::maskToPrefix($mask);
            return sprintf('%s/%d', $ip, $prefix);
        }

        return $default;
    }

    private static function fromRange(string $range): ?string
    {
        [$start, $end] = array_map('trim', explode('-', $range, 2));
        if (!filter_var($start, FILTER_VALIDATE_IP) || !filter_var($end, FILTER_VALIDATE_IP)) {
            return null;
        }

        $startLong = ip2long($start);
        $endLong = ip2long($end);
        if ($startLong === false || $endLong === false || $startLong > $endLong) {
            return null;
        }

        $diff = $startLong ^ $endLong;
        $mask = 32;
        while ($diff > 0 && $mask > 0) {
            $diff >>= 1;
            $mask--;
        }

        $hostBits = 32 - $mask;
        if ($hostBits >= 32) {
            $maskLong = 0;
        } else {
            $maskLong = (~((1 << $hostBits) - 1)) & 0xFFFFFFFF;
        }

        $networkLong = $startLong & $maskLong;
        $networkIp = long2ip($networkLong);
        if ($networkIp === false) {
            return null;
        }

        return sprintf('%s/%d', $networkIp, $mask);
    }

    private static function maskToPrefix(string $mask): int
    {
        $long = ip2long($mask);
        if ($long === false) {
            return 32;
        }

        return substr_count(str_pad(decbin($long & 0xFFFFFFFF), 32, '0', STR_PAD_LEFT), '1');
    }
}
