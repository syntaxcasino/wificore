<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\SystemMetricsController;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemMetricsControllerProvisioningGuardMetricsTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $keys = [
            'identity_validation_failed',
            'freshness_validation_failed',
            'terminal_status_mutation_ignored',
            'regressive_stage_ignored',
        ];

        foreach ($keys as $key) {
            Cache::forget('metrics:provisioning:callback_guard:' . $key);
            Cache::forget('metrics:provisioning:callback_guard:trend:' . $key);
        }

        Cache::forget('metrics:provisioning:callback_guard:last_updated_at');
    }

    #[Test]
    public function it_returns_provisioning_callback_guard_metrics_shape_and_total(): void
    {
        Cache::put('metrics:provisioning:callback_guard:identity_validation_failed', 2, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:freshness_validation_failed', 1, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:terminal_status_mutation_ignored', 3, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:regressive_stage_ignored', 4, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            now()->subMinutes(2)->format('Y-m-d\TH:i') => 2,
            now()->subMinutes(20)->format('Y-m-d\TH:i') => 9,
        ], now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:trend:freshness_validation_failed', [
            now()->subMinutes(1)->format('Y-m-d\TH:i') => 1,
        ], now()->addMinutes(5));
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
        $this->assertSame(2, $payload['last_10m_delta']['identity_validation_failed']);
        $this->assertSame(1, $payload['last_10m_delta']['freshness_validation_failed']);
        $this->assertSame(0, $payload['last_10m_delta']['terminal_status_mutation_ignored']);
        $this->assertSame(0, $payload['last_10m_delta']['regressive_stage_ignored']);
        $this->assertSame(3, $payload['last_10m_total_delta']);
        $this->assertArrayHasKey('last_updated_at', $payload);
        $this->assertArrayHasKey('last_10m_delta', $payload);
        $this->assertArrayHasKey('last_10m_total_delta', $payload);
    }


    #[Test]
    public function it_resets_provisioning_callback_guard_metrics(): void
    {
        Cache::put('metrics:provisioning:callback_guard:identity_validation_failed', 8, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:freshness_validation_failed', 6, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            now()->subMinutes(2)->format('Y-m-d\TH:i') => 2,
            now()->subMinutes(20)->format('Y-m-d\TH:i') => 9,
        ], now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:trend:freshness_validation_failed', [
            now()->subMinutes(1)->format('Y-m-d\TH:i') => 1,
        ], now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:last_updated_at', now()->toIso8601String(), now()->addMinutes(5));

        $controller = new SystemMetricsController();
        $response = $controller->resetProvisioningCallbackGuardMetrics();

        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);

        $this->assertSame(0, (int) Cache::get('metrics:provisioning:callback_guard:identity_validation_failed', 0));
        $this->assertSame(0, (int) Cache::get('metrics:provisioning:callback_guard:freshness_validation_failed', 0));
        $this->assertNull(Cache::get('metrics:provisioning:callback_guard:last_updated_at'));
        $this->assertNull(Cache::get('metrics:provisioning:callback_guard:trend:identity_validation_failed'));
    }

}
