<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeUserDisconnectedForNonPayment
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $pppoeUserId,
        public string $status,
        public ?string $reason = null,
        public ?string $source = null,
    ) {
    }
}
