<?php

namespace App\Events;

use App\Models\TenantRegistration;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class TenantRegistrationStarted
{
    use Dispatchable, InteractsWithSockets;

    public string $registrationId;
    public string $token;

    /**
     * Create a new event instance.
     */
    public function __construct(TenantRegistration $registration)
    {
        $this->registrationId = (string) $registration->id;
        $this->token = $registration->token;
    }
}
