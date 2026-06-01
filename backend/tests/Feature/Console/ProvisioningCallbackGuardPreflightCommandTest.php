<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class ProvisioningCallbackGuardPreflightCommandTest extends TestCase
{
    public function test_preflight_passes_with_safe_defaults(): void
    {
        config()->set('services.provisioning.require_callback_identity', false);
        config()->set('services.provisioning.warn_on_missing_callback_identity', true);
        config()->set('services.provisioning.reject_stale_callbacks', false);
        config()->set('services.provisioning.warn_on_stale_callbacks', true);
        config()->set('services.provisioning.max_callback_skew_seconds', 900);

        $this->artisan('provisioning:callback-guard-preflight')
            ->expectsOutput('Provisioning callback guard preflight passed.')
            ->assertExitCode(0);
    }

    public function test_preflight_fails_when_skew_threshold_is_invalid(): void
    {
        config()->set('services.provisioning.max_callback_skew_seconds', 0);

        $this->artisan('provisioning:callback-guard-preflight')
            ->assertExitCode(1);
    }

    public function test_preflight_strict_mode_fails_on_warnings(): void
    {
        config()->set('services.provisioning.require_callback_identity', true);
        config()->set('services.provisioning.warn_on_missing_callback_identity', false);
        config()->set('services.provisioning.max_callback_skew_seconds', 900);

        $this->artisan('provisioning:callback-guard-preflight --strict')
            ->expectsOutput('Preflight strict mode failed due to warnings.')
            ->assertExitCode(1);
    }
}
