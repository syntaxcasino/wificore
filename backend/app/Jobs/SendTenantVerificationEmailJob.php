<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Notifications\TenantEmailVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTenantVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    protected $tenantId;
    protected $username;
    protected $password;

    public function __construct(string $tenantId, string $username, string $password)
    {
        $this->tenantId = $tenantId;
        $this->username = $username;
        $this->password = $password;
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::error('Tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }
        
        if ($tenant->email_verified_at) {
            Log::info('Tenant email already verified, skipping email', [
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        try {
            $tenant->notify(new TenantEmailVerification(
                $tenant->slug,
                $tenant->name,
                $this->username,
                $this->password
            ));

            Log::info('Tenant verification email sent', [
                'tenant_id' => $tenant->id,
                'email' => $tenant->email,
                'username' => $this->username,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send tenant verification email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTenantVerificationEmailJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
