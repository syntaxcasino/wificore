<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeInvoiceSent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $pppoeUserId,
        public ?string $billingEmail = null,
        public ?string $nextPaymentDue = null,
        public ?string $source = null,
    ) {
    }
}
