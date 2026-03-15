<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PppoeInvoiceDueSoon
{
    use Dispatchable;

    public function __construct(
        public ?string $tenantId = null,
        public ?string $source = null,
    ) {
    }
}
