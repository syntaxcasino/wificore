<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeGracePeriodStarted
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $pppoeUserId,
        public string $status,
        public ?string $gracePeriodEndsAt = null,
        public ?string $source = null,
    ) {
    }
}
