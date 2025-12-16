<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Notifications\TenantCredentialsEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTenantCredentialsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            Log::error('Tenant not found for credentials email', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        if (!$tenant->email_verified_at) {
            Log::warning('Tenant email not verified, skipping credentials email', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        try {
            $tenant->notify(new TenantCredentialsEmail(
                $tenant->name,
                $tenant->slug,
                $this->username,
                $this->password,
                $tenant->slug
            ));

            // Mark credentials as sent
            $tenant->update([
                'is_active' => true,
                'settings' => array_merge($tenant->settings ?? [], [
                    'credentials_sent' => true,
                    'credentials_sent_at' => now()->toIso8601String(),
                ])
            ]);

            Log::info('Tenant credentials email sent', [
                'tenant_id' => $this->tenantId,
                'email' => $tenant->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send tenant credentials email', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTenantCredentialsEmailJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
