<?php

namespace App\Jobs;

use App\Events\PppoeInvoiceSent;
use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Notifications\PppoeInvoiceNotification;
use App\Services\TenantPaybillService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendPppoeInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [30, 60, 120];

    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if (!$this->tenantId) {
            $tenants = Tenant::active()->where('schema_created', true)->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info('Dispatched PPPoE invoice jobs', [
                'tenant_count' => $tenants->count(),
            ]);

            return;
        }

        $this->executeInTenantContext(function () {
            $paybillService = app(TenantPaybillService::class)
                ->setTenantId($this->tenantId)
                ->initialize();

            $pppoeUsers = PppoeUser::with('package')
                ->whereNotNull('next_payment_due')
                ->where('is_active', true)
                ->get()
                ->filter(function (PppoeUser $pppoeUser) {
                    return !empty($pppoeUser->getBillingEmail()) && $pppoeUser->shouldSendInvoice();
                });

            foreach ($pppoeUsers as $pppoeUser) {
                try {
                    $instructions = $paybillService->getPaymentInstructions($pppoeUser);

                    Notification::route('mail', $pppoeUser->getBillingEmail())
                        ->notify(new PppoeInvoiceNotification($pppoeUser, $instructions));

                    $pppoeUser->markInvoiceSent();

                    event(new PppoeInvoiceSent(
                        $this->tenantId,
                        $pppoeUser->id,
                        $pppoeUser->getBillingEmail(),
                        optional($pppoeUser->next_payment_due)->toIso8601String(),
                        'send_pppoe_invoices_job'
                    ));
                } catch (\Exception $e) {
                    Log::error('Failed to send PPPoE invoice', [
                        'tenant_id' => $this->tenantId,
                        'pppoe_user_id' => $pppoeUser->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Completed PPPoE invoice job', [
                'tenant_id' => $this->tenantId,
                'invoices_sent' => $pppoeUsers->count(),
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('SendPppoeInvoicesJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
