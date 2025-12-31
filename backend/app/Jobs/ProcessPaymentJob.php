<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\UserProvisioningService;
use App\Events\PaymentProcessed;
use App\Events\PaymentFailed;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $paymentId;
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30, 60];

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($paymentId, $tenantId)
    {
        $this->paymentId = $paymentId;
        $this->setTenantContext($tenantId);
        $this->onQueue('payments'); // Dedicated queue for payments
    }

    /**
     * Execute the job.
     */
    public function handle(UserProvisioningService $provisioningService): void
    {
        $this->executeInTenantContext(function() use ($provisioningService) {
            Log::info('Processing payment job started', [
                'job_id' => $this->job->getJobId(),
                'payment_id' => $this->paymentId,
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
            ]);

            try {
                $payment = Payment::find($this->paymentId);

                if (!$payment) {
                    Log::error('Payment not found during processing', [
                        'payment_id' => $this->paymentId,
                        'tenant_id' => $this->tenantId
                    ]);
                    return;
                }

                // Check if payment is already processed
                if ($payment->isCompleted()) {
                    Log::info('Payment already processed, skipping', [
                        'payment_id' => $payment->id
                    ]);
                    return;
                }

                // Process payment and provision user
                $result = $provisioningService->processPayment($payment);

                // Mark payment as completed
                $payment->markAsCompleted();

                Log::info('Payment processed successfully', [
                    'payment_id' => $payment->id,
                    'user_id' => $result['user']->id,
                    'subscription_id' => $result['subscription']->id,
                ]);

                // Broadcast success event to admins
                broadcast(new PaymentProcessed(
                    $payment,
                    $result['user'],
                    $result['subscription'],
                    $result['credentials']
                ))->toOthers();

            } catch (\Exception $e) {
                Log::error('Payment processing failed', [
                    'payment_id' => $this->paymentId,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Re-fetch payment to ensure we have the model for failure handling
                $payment = Payment::find($this->paymentId);

                // If this is the last attempt, mark payment as failed
                if ($this->attempts() >= $this->tries && $payment) {
                    $payment->markAsFailed();
                    
                    // Broadcast failure event to admins
                    broadcast(new PaymentFailed(
                        $payment,
                        $e->getMessage()
                    ))->toOthers();
                }

                // Re-throw to trigger retry
                throw $e;
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Payment job failed permanently', [
            'payment_id' => $this->paymentId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);

        // Note: We can't easily mark as failed here without context, 
        // but handle() try-catch block covers the last attempt failure logic.
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'payment:' . $this->paymentId,
            'tenant:' . $this->tenantId,
        ];
    }
}
