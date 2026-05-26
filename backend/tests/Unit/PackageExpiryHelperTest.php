<?php

use App\Helpers\PackageExpiryHelper;
use App\Models\Package;
use Illuminate\Support\Carbon;

it('extends renewal from the later current expiry instead of the payment date', function () {
    $package = new Package(['validity' => '30 days']);
    $paymentDate = Carbon::parse('2026-05-01 10:00:00');
    $currentExpiry = Carbon::parse('2026-05-20 12:00:00');

    $renewalStart = PackageExpiryHelper::resolveRenewalBaseTime($paymentDate, $currentExpiry);
    $renewalEnd = PackageExpiryHelper::calculateRenewalExpiresAt($package, $paymentDate, $currentExpiry);

    expect($renewalStart->toDateTimeString())->toBe('2026-05-20 12:00:00');
    expect($renewalEnd->toDateTimeString())->toBe('2026-06-19 12:00:00');
});

it('uses the payment date when there is no later current expiry', function () {
    $package = new Package(['validity' => '7 days']);
    $paymentDate = Carbon::parse('2026-05-01 10:00:00');
    $currentExpiry = Carbon::parse('2026-04-30 10:00:00');

    $renewalStart = PackageExpiryHelper::resolveRenewalBaseTime($paymentDate, $currentExpiry);
    $renewalEnd = PackageExpiryHelper::calculateRenewalExpiresAt($package, $paymentDate, $currentExpiry);

    expect($renewalStart->toDateTimeString())->toBe('2026-05-01 10:00:00');
    expect($renewalEnd->toDateTimeString())->toBe('2026-05-08 10:00:00');
});
