<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Package;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired when a hotspot user needs to be provisioned
 * This triggers async provisioning job
 */
class HotspotUserProvisionRequested
{
    use Dispatchable, InteractsWithSockets;

    public string $paymentId;
    public string $packageId;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, Package $package)
    {
        $this->paymentId = (string) $payment->id;
        $this->packageId = (string) $package->id;
    }
}
