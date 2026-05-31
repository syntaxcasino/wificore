<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TenantIsolationGuardCommandTest extends TestCase
{
    public function test_tenant_isolation_guard_command_runs_successfully(): void
    {
        $this->artisan('tenant:isolation-guard --fail-on-findings')
            ->assertExitCode(0);
    }

    public function test_tenant_isolation_guard_fails_when_forbidden_pattern_is_detected(): void
    {
        $fixtureRelativePath = 'storage/framework/testing/tenant_isolation_guard_fixture.php';
        $fixtureAbsolutePath = base_path($fixtureRelativePath);

        File::ensureDirectoryExists(dirname($fixtureAbsolutePath));
        File::put($fixtureAbsolutePath, "<?php\nDB::table('users')->count();\n");

        $originalCriticalFiles = config('tenant_isolation.critical_files');
        $originalPatterns = config('tenant_isolation.forbidden_patterns');
        $originalAllowlist = config('tenant_isolation.allowlist');

        try {
            config()->set('tenant_isolation.critical_files', [$fixtureRelativePath]);
            config()->set('tenant_isolation.forbidden_patterns', ['/DB::table\\s*\\(/']);
            config()->set('tenant_isolation.allowlist', []);

            $this->artisan('tenant:isolation-guard --fail-on-findings')
                ->assertExitCode(1);
        } finally {
            config()->set('tenant_isolation.critical_files', $originalCriticalFiles);
            config()->set('tenant_isolation.forbidden_patterns', $originalPatterns);
            config()->set('tenant_isolation.allowlist', $originalAllowlist);

            if (File::exists($fixtureAbsolutePath)) {
                File::delete($fixtureAbsolutePath);
            }
        }
    }
}
