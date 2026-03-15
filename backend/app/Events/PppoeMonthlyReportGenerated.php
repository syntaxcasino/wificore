<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeMonthlyReportGenerated
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $reportMonth,
        public string $reportPath,
        public int $totalPayments,
        public float $totalAmount,
        public int $paidAccounts,
        public int $unpaidAccounts,
        public ?string $source = null,
    ) {
    }
}
