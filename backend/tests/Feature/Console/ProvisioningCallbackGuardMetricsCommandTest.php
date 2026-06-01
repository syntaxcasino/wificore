<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProvisioningCallbackGuardMetricsCommandTest extends TestCase
{
    public function test_it_displays_callback_guard_counters(): void
    {
        Cache::put('metrics:provisioning:callback_guard:identity_validation_failed', 3, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:regressive_stage_ignored', 2, now()->addMinutes(5));

        $this->artisan('provisioning:callback-guard-metrics')
            ->expectsOutputToContain('Total guard outcomes: 5')
            ->assertExitCode(0);
    }

    public function test_it_resets_callback_guard_counters(): void
    {
        Cache::put('metrics:provisioning:callback_guard:identity_validation_failed', 7, now()->addMinutes(5));
        Cache::put('metrics:provisioning:callback_guard:last_updated_at', now()->toIso8601String(), now()->addMinutes(5));

        $this->artisan('provisioning:callback-guard-metrics --reset')
            ->expectsOutput('Provisioning callback guard counters reset.')
            ->assertExitCode(0);

        $this->assertSame(0, (int) Cache::get('metrics:provisioning:callback_guard:identity_validation_failed', 0));
        $this->assertNull(Cache::get('metrics:provisioning:callback_guard:last_updated_at'));
    }
}
