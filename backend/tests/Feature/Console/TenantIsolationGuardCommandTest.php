<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class TenantIsolationGuardCommandTest extends TestCase
{
    public function test_tenant_isolation_guard_command_runs_successfully(): void
    {
        $this->artisan('tenant:isolation-guard --fail-on-findings')
            ->assertExitCode(0);
    }
}
