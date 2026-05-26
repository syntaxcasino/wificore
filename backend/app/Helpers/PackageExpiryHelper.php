<?php

namespace App\Helpers;

use App\Models\Package;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PackageExpiryHelper
{
    /**
     * Calculate the expiry date from a package's validity string relative to a base time.
     *
     * Validity format examples: "30 days", "1 month", "7 days", "1 year"
     * Falls back to +1 hour if validity is missing or unrecognised.
     */
    public static function calculateExpiresAt(Package $package, CarbonInterface $baseTime): Carbon
    {
        $base = Carbon::instance($baseTime);
        $validity = trim((string) ($package->validity ?: $package->duration));

        if ($validity === '') {
            return $base->copy()->addHour();
        }

        if (!preg_match('/^\s*(\d+)\s*(minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)\s*$/i', $validity, $matches)) {
            return $base->copy()->addHour();
        }

        $value = (int) $matches[1];
        $unit  = strtolower($matches[2]);

        if ($value <= 0) {
            return $base->copy()->addHour();
        }

        return match ($unit) {
            'minute', 'minutes' => $base->copy()->addMinutes($value),
            'hour',   'hours'   => $base->copy()->addHours($value),
            'day',    'days'    => $base->copy()->addDays($value),
            'week',   'weeks'   => $base->copy()->addWeeks($value),
            'month',  'months'  => $base->copy()->addMonths($value),
            'year',   'years'   => $base->copy()->addYears($value),
            default             => $base->copy()->addHour(),
        };
    }

    /**
     * Derive the package duration in whole days (approximate).
     * Used for pro-rating calculations when a package changes mid-subscription.
     *
     * Returns 1 at minimum to prevent division-by-zero.
     */
    public static function resolveRenewalBaseTime(CarbonInterface $paymentTime, ?CarbonInterface $currentExpiry = null): Carbon
    {
        $base = Carbon::instance($paymentTime);

        if ($currentExpiry) {
            $existingExpiry = Carbon::instance($currentExpiry);
            if ($existingExpiry->greaterThan($base)) {
                $base = $existingExpiry;
            }
        }

        return $base;
    }

    public static function calculateRenewalExpiresAt(Package $package, CarbonInterface $paymentTime, ?CarbonInterface $currentExpiry = null): Carbon
    {
        return self::calculateExpiresAt($package, self::resolveRenewalBaseTime($paymentTime, $currentExpiry));
    }

    public static function durationInDays(Package $package): int
    {
        $validity = trim((string) ($package->validity ?: $package->duration));

        if ($validity === '' || !preg_match('/^\s*(\d+)\s*(minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)\s*$/i', $validity, $matches)) {
            return 1;
        }

        $value = (int) $matches[1];
        $unit  = strtolower($matches[2]);

        if ($value <= 0) {
            return 1;
        }

        return match ($unit) {
            'minute', 'minutes' => max(1, (int) ceil($value / 1440)),
            'hour',   'hours'   => max(1, (int) ceil($value / 24)),
            'day',    'days'    => max(1, $value),
            'week',   'weeks'   => max(1, $value * 7),
            'month',  'months'  => max(1, $value * 30),
            'year',   'years'   => max(1, $value * 365),
            default             => 1,
        };
    }
}
