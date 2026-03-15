<?php

namespace App\Listeners;

use App\Events\PppoeGracePeriodStarted;
use App\Events\PppoeUserDisconnectedForNonPayment;
use App\Events\PppoeUserReconnectedAfterPayment;
use Illuminate\Support\Facades\Log;

class LogPppoeBillingLifecycle
{
    public function handle(object $event): void
    {
        $context = [
            'tenant_id' => $event->tenantId ?? null,
            'pppoe_user_id' => $event->pppoeUserId ?? null,
            'status' => $event->status ?? null,
            'source' => $event->source ?? null,
        ];

        if ($event instanceof PppoeGracePeriodStarted) {
            $context['grace_period_ends_at'] = $event->gracePeriodEndsAt;
            Log::info('PPPoE billing lifecycle grace started', $context);
            return;
        }

        if ($event instanceof PppoeUserDisconnectedForNonPayment) {
            $context['reason'] = $event->reason;
            Log::warning('PPPoE billing lifecycle disconnected for non-payment', $context);
            return;
        }

        if ($event instanceof PppoeUserReconnectedAfterPayment) {
            $context['payment_id'] = $event->paymentId;
            Log::info('PPPoE billing lifecycle reconnected after payment', $context);
        }
    }
}
