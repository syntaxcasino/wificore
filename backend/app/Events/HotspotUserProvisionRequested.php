<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Package;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a hotspot user needs to be provisioned
 * This triggers async provisioning job
 */
class HotspotUserProvisionRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;
    public Package $package;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment, Package $package)
    {
        $this->payment = $payment;
        $this->package = $package;
    }
}
