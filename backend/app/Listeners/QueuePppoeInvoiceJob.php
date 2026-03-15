<?php

namespace App\Listeners;

use App\Events\PppoeInvoiceDueSoon;
use App\Jobs\SendPppoeInvoicesJob;

class QueuePppoeInvoiceJob
{
    public function handle(PppoeInvoiceDueSoon $event): void
    {
        SendPppoeInvoicesJob::dispatch($event->tenantId);
    }
}
