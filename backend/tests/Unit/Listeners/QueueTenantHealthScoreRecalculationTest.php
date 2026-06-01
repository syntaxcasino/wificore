<?php

namespace Tests\Unit\Listeners;

use App\Events\PaymentCompleted;
use App\Jobs\UpdateTenantHealthScoreJob;
use App\Listeners\QueueTenantHealthScoreRecalculation;
use App\Models\Payment;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueueTenantHealthScoreRecalculationTest extends TestCase
{
    #[Test]
    public function it_dispatches_a_health_score_recalculation_job_for_event_tenant(): void
    {
        Bus::fake();

        $payment = new Payment([
            'id' => 'payment-1',
            'amount' => 100,
            'phone_number' => '+254700000000',
            'package_id' => 'package-1',
            'status' => 'completed',
        ]);

        $event = new PaymentCompleted($payment, 'tenant-123');

        (new QueueTenantHealthScoreRecalculation())->handle($event);

        Bus::assertDispatched(UpdateTenantHealthScoreJob::class, function (UpdateTenantHealthScoreJob $job) {
            return $job->tenantId === 'tenant-123';
        });
    }
}
