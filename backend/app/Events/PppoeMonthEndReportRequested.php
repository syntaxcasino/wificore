<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeMonthEndReportRequested
{
    use Dispatchable;

    public function __construct(
        public ?string $tenantId = null,
        public ?string $reportMonth = null,
        public ?string $source = null,
    ) {
    }
}
