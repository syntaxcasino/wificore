<?php

namespace App\Listeners;

use App\Events\PppoePaymentReminderDue;
use App\Jobs\SendPppoePaymentRemindersJob;

class QueuePppoePaymentReminderJob
{
    public function handle(PppoePaymentReminderDue $event): void
    {
        SendPppoePaymentRemindersJob::dispatch($event->tenantId);
    }
}
