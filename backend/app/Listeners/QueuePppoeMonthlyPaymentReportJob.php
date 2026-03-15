<?php

namespace App\Listeners;

use App\Events\PppoeMonthEndReportRequested;
use App\Jobs\GeneratePppoeMonthlyPaymentReportJob;

class QueuePppoeMonthlyPaymentReportJob
{
    public function handle(PppoeMonthEndReportRequested $event): void
    {
        GeneratePppoeMonthlyPaymentReportJob::dispatch($event->tenantId);
    }
}
