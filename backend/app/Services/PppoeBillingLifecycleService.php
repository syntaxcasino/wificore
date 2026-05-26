<?php

namespace App\Services;

use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Helpers\PackageExpiryHelper;
use App\Jobs\ReconnectPppoeUserJob;
use App\Models\PppoePayment;
use App\Models\PppoeUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PppoeBillingLifecycleService
{
    public function handleSuccessfulPayment(PppoeUser $user, PppoePayment $payment, string $tenantId, string $source): void
    {
        $wasInactive = in_array($user->status, ['suspended', 'pending', 'expired'], true);

        $user->loadMissing('package');

        $paymentDate = $payment->payment_date ? Carbon::parse($payment->payment_date) : now();
        $currentExpiry = $user->expires_at ? Carbon::parse($user->expires_at) : null;
        $effectiveStart = PackageExpiryHelper::resolveRenewalBaseTime($paymentDate, $currentExpiry);
        $effectiveEnd = $user->package
            ? PackageExpiryHelper::calculateRenewalExpiresAt($user->package, $paymentDate, $currentExpiry)
            : $effectiveStart->copy()->addDays(30);

        $payment->forceFill([
            'period_start' => $effectiveStart,
            'period_end' => $effectiveEnd,
            'payment_date' => $paymentDate,
        ])->save();

        $user->activateAfterPayment();
        $user->last_payment_date = $paymentDate;
        $user->next_payment_due  = $effectiveEnd;
        $user->expires_at        = $effectiveEnd;
        $user->amount_paid       = $payment->amount;
        $user->payment_method    = $payment->payment_method;
        $user->payment_reference = $payment->payment_reference;
        $user->save();

        DB::table('radcheck')
            ->where('username', $user->username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        if ($wasInactive) {
            DB::afterCommit(function () use ($user, $tenantId) {
                $key = $this->reconnectJobCacheKey($tenantId, $user->id);
                Cache::put($key, [
                    'status' => 'queued',
                    'tenant_id' => $tenantId,
                    'pppoe_user_id' => $user->id,
                    'queued_at' => now()->toIso8601String(),
                    'queue' => 'service-control',
                ], now()->addHour());

                ReconnectPppoeUserJob::dispatch($user->id, $tenantId);
            });
        } else {
            Cache::forget($this->reconnectJobCacheKey($tenantId, $user->id));
        }

        event(new PaymentReceived($tenantId, $user->id, $payment->id, (float) $payment->amount));
        event(new PppoeUserPaymentStatusChanged(
            $tenantId,
            $user->id,
            'paid',
            $wasInactive ? 'reconnected' : 'renewed'
        ));

        Log::info('PPPoE billing lifecycle payment completed', [
            'tenant_id' => $tenantId,
            'pppoe_user_id' => $user->id,
            'payment_id' => $payment->id,
            'payment_method' => $payment->payment_method,
            'payment_status' => $user->payment_status,
            'status' => $user->status,
            'was_inactive' => $wasInactive,
            'source' => $source,
        ]);
    }

    private function reconnectJobCacheKey(string $tenantId, string $pppoeUserId): string
    {
        return 'pppoe_reconnect_job:' . $tenantId . ':' . $pppoeUserId;
    }
}
