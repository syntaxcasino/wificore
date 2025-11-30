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

    public $payment;
    
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
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
        $this->setTenantContext($payment->tenant_id);
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
                'payment_id' => $this->payment->id,
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
            ]);

        try {
            // Check if payment is already processed
            if ($this->payment->isCompleted()) {
                Log::info('Payment already processed, skipping', [
                    'payment_id' => $this->payment->id
                ]);
                return;
            }

            // Process payment and provision user
            $result = $provisioningService->processPayment($this->payment);

            // Mark payment as completed
            $this->payment->markAsCompleted();

            Log::info('Payment processed successfully', [
                'payment_id' => $this->payment->id,
                'user_id' => $result['user']->id,
                'subscription_id' => $result['subscription']->id,
            ]);

            // Broadcast success event to admins
            broadcast(new PaymentProcessed(
                $this->payment,
                $result['user'],
                $result['subscription'],
                $result['credentials']
            ))->toOthers();

        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'payment_id' => $this->payment->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If this is the last attempt, mark payment as failed
            if ($this->attempts() >= $this->tries) {
                $this->payment->markAsFailed();
                
                // Broadcast failure event to admins
                broadcast(new PaymentFailed(
                    $this->payment,
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
            'payment_id' => $this->payment->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark payment as failed
        $this->payment->markAsFailed();

        // Broadcast failure event to admins
        broadcast(new PaymentFailed(
            $this->payment,
            $exception->getMessage()
        ))->toOthers();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'payment:' . $this->payment->id,
            'phone:' . $this->payment->phone_number,
            'package:' . $this->payment->package_id,
        ];
    }
}
