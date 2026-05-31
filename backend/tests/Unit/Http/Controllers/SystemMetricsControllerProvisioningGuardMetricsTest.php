<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\SystemMetricsController;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemMetricsControllerProvisioningGuardMetricsTest extends TestCase
{
    #[Test]
    public function it_returns_provisioning_callback_guard_metrics_shape_and_total(): void
    {
        Cache::put('metrics:provisioning:callback_guard:identity_validation_failed', 2, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:freshness_validation_failed', 1, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:terminal_status_mutation_ignored', 3, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:regressive_stage_ignored', 4, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:last_updated_at', now()->toIso8601String(), now()->addMinutes(5));

        $controller = new SystemMetricsController();
        $response = $controller->getProvisioningCallbackGuardMetrics();

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertSame(10, $payload['total']);
        $this->assertSame(2, $payload['counters']['identity_validation_failed']);
        $this->assertSame(1, $payload['counters']['freshness_validation_failed']);
        $this->assertSame(3, $payload['counters']['terminal_status_mutation_ignored']);
        $this->assertSame(4, $payload['counters']['regressive_stage_ignored']);
        $this->assertArrayHasKey('last_updated_at', $payload);
    }
}
