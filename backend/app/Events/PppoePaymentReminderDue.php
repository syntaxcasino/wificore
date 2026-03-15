<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoePaymentReminderDue
{
    use Dispatchable;

    public function __construct(
        public ?string $tenantId = null,
        public ?string $source = null,
    ) {
    }
}
