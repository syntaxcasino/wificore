<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeUserReconnectedAfterPayment
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $pppoeUserId,
        public string $status,
        public ?string $paymentId = null,
        public ?string $source = null,
    ) {
    }
}
