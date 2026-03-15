<?php

namespace App\Jobs;

use App\Models\PppoePayment;
use App\Models\PppoeUser;
use App\Notifications\PppoePaymentReceiptNotification;
use App\Services\MessagingService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendPppoePaymentReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    public function __construct(
        protected string $pppoeUserId,
        string $tenantId,
        protected string $paymentId,
    ) {
        $this->setTenantContext($tenantId);
        $this->onQueue('notifications');
    }

    public function handle(MessagingService $messagingService): void
    {
        $this->executeInTenantContext(function () use ($messagingService) {
            $pppoeUser = PppoeUser::with('package')->find($this->pppoeUserId);
            $payment = PppoePayment::find($this->paymentId);

            if (!$pppoeUser || !$payment || $payment->status !== 'completed') {
                return;
            }

            $metadata = $payment->metadata ?? [];
            if (!empty($metadata['receipt_sent_at'])) {
                return;
            }

            $receiptNumber = (string) ($metadata['receipt_number'] ?? ('PPPR-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) $payment->id, 0, 6))));

            if ($pppoeUser->getBillingEmail()) {
                Notification::route('mail', $pppoeUser->getBillingEmail())
                    ->notify(new PppoePaymentReceiptNotification($pppoeUser, $payment, $receiptNumber));
            }

            if ($pppoeUser->getBillingPhone()) {
                $message = sprintf(
                    'Payment received for account %s. Amount: KES %s. Receipt: %s. Transaction: %s.',
                    $pppoeUser->account_number,
                    number_format((float) $payment->amount, 2),
                    $receiptNumber,
                    $payment->transaction_id ?: 'N/A'
                );

                $messagingService->sendViaDefaultChannel('sms', $pppoeUser->getBillingPhone(), $message);
            }

            $metadata['receipt_number'] = $receiptNumber;
            $metadata['receipt_sent_at'] = now()->toIso8601String();
            $payment->metadata = $metadata;
            $payment->save();

            $pppoeUser->markReceiptSent();

            Log::info('PPPoE payment receipt sent', [
                'tenant_id' => $this->tenantId,
                'pppoe_user_id' => $pppoeUser->id,
                'payment_id' => $payment->id,
                'receipt_number' => $receiptNumber,
            ]);
        });
    }
}
