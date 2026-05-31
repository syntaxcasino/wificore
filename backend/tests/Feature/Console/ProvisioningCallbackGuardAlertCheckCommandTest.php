<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProvisioningCallbackGuardAlertCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'identity_validation_failed',
            'freshness_validation_failed',
            'terminal_status_mutation_ignored',
            'regressive_stage_ignored',
        ] as $action) {
            Cache::forget('metrics:provisioning:callback_guard:trend:' . $action);
        }

        Cache::forget('metrics:provisioning:callback_guard:alert_cooldown');
    }

    public function test_it_reports_normal_when_below_threshold(): void
    {
        $minuteKey = now()->format('Y-m-d\\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 1,
        ], now()->addMinutes(10));

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutput('Callback guard trend is within normal range.')
            ->assertExitCode(0);
    }

    public function test_it_emits_alert_and_sets_cooldown_when_threshold_exceeded(): void
    {
        $minuteKey = now()->format('Y-m-d\\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 7,
        ], now()->addMinutes(10));

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        $cooldown = Cache::get('metrics:provisioning:callback_guard:alert_cooldown');
        $this->assertIsArray($cooldown);
        $this->assertSame(7, (int) ($cooldown['total_delta'] ?? 0));
    }
}
