<?php

namespace App\Listeners;

use App\Events\PaymentReceived;
use App\Jobs\SendPppoePaymentReceiptJob;

class QueuePppoePaymentReceipt
{
    public function handle(PaymentReceived $event): void
    {
        SendPppoePaymentReceiptJob::dispatch($event->userId, $event->tenantId, $event->paymentId);
    }
}
