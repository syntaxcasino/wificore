<?php

namespace App\Services\MikroTik;

class BandwidthHelper
{
    private const UNIT_MULTIPLIERS = [
        'K' => 1000,
        'M' => 1000000,
        'G' => 1000000000,
    ];

    /**
     * Normalize speed string to integer (bps) or standard format
     * 
     * @param string $speed
     * @return string|null
     */
    public static function normalizeSpeed(string $speed): ?string
    {
        $speed = trim($speed);
        if ($speed === '') {
            return null;
        }

        if (is_numeric($speed)) {
            return (string) max(0, (int) round((float) $speed));
        }

        // Support shorthand (e.g. 10M, 2.5G) and explicit units (e.g. 10 Mbps, 512 Kbps)
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])(?:bps)?$/i', $speed, $m)) {
            $value = (float) $m[1];
            $unit = strtoupper($m[2]);

            return self::formatBitsPerSecond((int) round($value * self::UNIT_MULTIPLIERS[$unit]));
        }

        return $speed;
    }

    private static function formatBitsPerSecond(int $bitsPerSecond): string
    {
        $bitsPerSecond = max(0, $bitsPerSecond);

        if ($bitsPerSecond % self::UNIT_MULTIPLIERS['G'] === 0 && $bitsPerSecond >= self::UNIT_MULTIPLIERS['G']) {
            return (string) ($bitsPerSecond / self::UNIT_MULTIPLIERS['G']) . 'G';
        }

        if ($bitsPerSecond % self::UNIT_MULTIPLIERS['M'] === 0 && $bitsPerSecond >= self::UNIT_MULTIPLIERS['M']) {
            return (string) ($bitsPerSecond / self::UNIT_MULTIPLIERS['M']) . 'M';
        }

        if ($bitsPerSecond % self::UNIT_MULTIPLIERS['K'] === 0 && $bitsPerSecond >= self::UNIT_MULTIPLIERS['K']) {
            return (string) ($bitsPerSecond / self::UNIT_MULTIPLIERS['K']) . 'K';
        }

        return (string) $bitsPerSecond;
    }

    /**
     * Format bandwidth limit for MikroTik (Upload/Download)
     * 
     * @param string $download
     * @param string $upload
     * @return string|null
     */
    public static function formatMikrotikRateLimit(string $download, string $upload): ?string
    {
        $down = self::normalizeSpeed($download);
        $up = self::normalizeSpeed($upload);

        if (!$down && !$up) {
            return null;
        }

        // MikroTik expects RX/TX (Upload/Download from client perspective)
        return ($up ?: '0') . '/' . ($down ?: '0');
    }
}
