<?php

namespace App\Services;

use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Jobs\ReconnectPppoeUserJob;
use App\Models\PppoePayment;
use App\Models\PppoeUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PppoeBillingLifecycleService
{
    public function handleSuccessfulPayment(PppoeUser $user, PppoePayment $payment, string $tenantId, string $source): void
    {
        $wasInactive = in_array($user->status, ['suspended', 'pending', 'expired'], true);

        $user->activateAfterPayment();
        $user->last_payment_date = $payment->payment_date ?? now();
        $user->next_payment_due  = $payment->period_end  ?? now()->addDays(30);
        $user->expires_at        = $payment->period_end  ?? now()->addDays(30);
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
            ReconnectPppoeUserJob::dispatch($user->id, $tenantId);
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
}
