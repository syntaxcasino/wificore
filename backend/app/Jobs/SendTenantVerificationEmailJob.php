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

    protected $tenantId;
    protected $tenantSlug;
    protected $tenantName;

    public function __construct(string $tenantId, string $tenantSlug, string $tenantName)
    {
        $this->tenantId = $tenantId;
        $this->tenantSlug = $tenantSlug;
        $this->tenantName = $tenantName;
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant) {
            Log::error('Tenant not found for verification email', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        if ($tenant->email_verified_at) {
            Log::info('Tenant email already verified, skipping email', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        try {
            $tenant->notify(new TenantEmailVerification($this->tenantSlug, $this->tenantName));

            Log::info('Tenant verification email sent', [
                'tenant_id' => $this->tenantId,
                'email' => $tenant->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send tenant verification email', [
                'tenant_id' => $this->tenantId,
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
