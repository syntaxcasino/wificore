<?php

namespace App\Jobs;

use App\Events\PppoeReminderSent;
use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Notifications\PppoePaymentReminderNotification;
use App\Services\MessagingService;
use App\Services\TenantPaybillService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendPppoePaymentRemindersJob implements ShouldQueue
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

    public function handle(MessagingService $messagingService): void
    {
        if (!$this->tenantId) {
            $tenants = Tenant::active()->where('schema_created', true)->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info('Dispatched PPPoE payment reminder jobs', [
                'tenant_count' => $tenants->count(),
            ]);

            return;
        }

        $this->executeInTenantContext(function () use ($messagingService) {
            $paybillService = app(TenantPaybillService::class)
                ->setTenantId($this->tenantId)
                ->initialize();

            // OPTIMIZED: Select only needed columns with selective eager loading
            $pppoeUsers = PppoeUser::query()
                ->select([
                    'id', 'username', 'account_number', 'next_payment_due', 'is_active',
                    'customer_email', 'customer_phone', 'portal_password', 'payment_reference',
                    'amount_due', 'package_id', 'last_reminder_sent_at', 'reminder_count'
                ])
                ->with(['package:id,name,price'])
                ->whereNotNull('next_payment_due')
                ->where('is_active', true)
                ->get()
                ->filter(function (PppoeUser $pppoeUser) {
                    return $pppoeUser->canReceiveBillingNotifications() && $pppoeUser->needsBillingReminder();
                });

            foreach ($pppoeUsers as $pppoeUser) {
                try {
                    $daysUntilDue = now()->diffInDays($pppoeUser->next_payment_due, false);
                    $instructions = $paybillService->getPaymentInstructions($pppoeUser);

                    if ($pppoeUser->getBillingEmail()) {
                        Notification::route('mail', $pppoeUser->getBillingEmail())
                            ->notify(new PppoePaymentReminderNotification($pppoeUser, $daysUntilDue, $instructions));
                    }

                    if ($pppoeUser->getBillingPhone()) {
                        $message = sprintf(
                            'Reminder: PPPoE account %s is due in %d day(s). Amount: KES %s. Paybill: %s. Ref: %s.',
                            $pppoeUser->account_number,
                            max($daysUntilDue, 0),
                            number_format((float) ($pppoeUser->amount_due ?? $pppoeUser->package?->price ?? 0), 2),
                            $instructions['paybill_number'] ?? 'N/A',
                            $instructions['account_number'] ?? $pppoeUser->account_number
                        );

                        $messagingService->sendViaDefaultChannel('sms', $pppoeUser->getBillingPhone(), $message);
                    }

                    $pppoeUser->markReminderSent();

                    event(new PppoeReminderSent(
                        $this->tenantId,
                        $pppoeUser->id,
                        $pppoeUser->getBillingEmail(),
                        $pppoeUser->getBillingPhone(),
                        optional($pppoeUser->next_payment_due)->toIso8601String(),
                        'send_pppoe_payment_reminders_job'
                    ));
                } catch (\Exception $e) {
                    Log::error('Failed to send PPPoE payment reminder', [
                        'tenant_id' => $this->tenantId,
                        'pppoe_user_id' => $pppoeUser->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Completed PPPoE payment reminders job', [
                'tenant_id' => $this->tenantId,
                'reminders_sent' => $pppoeUsers->count(),
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('SendPppoePaymentRemindersJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
