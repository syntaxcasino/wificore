<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Notifications\TenantEmailVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTenantVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    protected $tenant;
    protected $username;
    protected $password;

    public function __construct(Tenant $tenant, string $username, string $password)
    {
        $this->tenant = $tenant;
        $this->username = $username;
        $this->password = $password;
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if ($this->tenant->email_verified_at) {
            Log::info('Tenant email already verified, skipping email', [
                'tenant_id' => $this->tenant->id,
            ]);
            return;
        }

        try {
            $this->tenant->notify(new TenantEmailVerification(
                $this->tenant->slug,
                $this->tenant->name,
                $this->username,
                $this->password
            ));

            Log::info('Tenant verification email sent', [
                'tenant_id' => $this->tenant->id,
                'email' => $this->tenant->email,
                'username' => $this->username,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send tenant verification email', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTenantVerificationEmailJob failed permanently', [
            'tenant_id' => $this->tenant->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
