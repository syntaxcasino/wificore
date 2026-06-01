<?php

namespace Tests\Feature;

use App\Models\ProvisioningRun;
use App\Models\ProvisioningStep;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;
use Tests\TestCase;

class RouterProvisioningLedgerTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    private Tenant $tenant;
    private User $admin;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->setUpTestTenant();
        $this->admin = $this->createAdminUser($this->tenant);
        $this->router = $this->createRouter($this->tenant, [
            'name' => 'Ledger Router',
            'status' => 'online',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenantContext();
        parent::tearDown();
    }

    private function createRun(array $overrides = []): ProvisioningRun
    {
        return ProvisioningRun::create(array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'router_id' => $this->router->id,
            'source' => 'router_task',
            'mode' => 'deploy',
            'status' => ProvisioningRun::STATUS_RUNNING,
            'progress' => 60,
            'current_stage' => 'deploying',
            'started_at' => now()->subMinutes(5),
        ], $overrides));
    }

    private function createStep(ProvisioningRun $run, array $overrides = []): ProvisioningStep
    {
        return ProvisioningStep::create(array_merge([
            'id' => Str::uuid()->toString(),
            'provisioning_run_id' => $run->id,
            'tenant_id' => $this->tenant->id,
            'router_id' => $this->router->id,
            'sequence' => 1,
            'stage' => 'deploy',
            'action' => 'execute_command',
            'status' => ProvisioningStep::STATUS_COMPLETED,
            'command' => '/interface bridge add name=br-test',
            'response_payload' => ['.id' => '*1'],
            'started_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(4),
        ], $overrides));
    }

    public function test_it_returns_router_provisioning_runs_with_steps(): void
    {
        $oldRun = $this->createRun([
            'id' => Str::uuid()->toString(),
            'progress' => 25,
            'current_stage' => 'validating',
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $this->createStep($oldRun, [
            'sequence' => 1,
            'stage' => 'validate',
            'command' => '/system resource print',
            'response_payload' => ['status' => 'ok'],
            'completed_at' => now()->subHour(),
        ]);

        $latestRun = $this->createRun([
            'status' => ProvisioningRun::STATUS_FAILED,
            'progress' => 80,
            'current_stage' => 'rollback_dispatched',
            'error_message' => 'Trap encountered',
            'completed_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(1),
        ]);
        $this->createStep($latestRun, [
            'sequence' => 1,
            'stage' => 'deploy',
            'command' => '/ip address add address=10.10.10.1/24 interface=bridge1',
            'response_payload' => ['.id' => '*2'],
            'completed_at' => now()->subMinutes(1),
        ]);
        $this->createStep($latestRun, [
            'sequence' => 2,
            'stage' => 'deploy',
            'action' => 'execute_command',
            'status' => ProvisioningStep::STATUS_FAILED,
            'command' => '/queue simple add name=queue-test',
            'trap_message' => 'trap: interface not found',
            'error_message' => 'interface not found',
            'completed_at' => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/routers/' . $this->router->id . '/provisioning-runs');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('latest_run.id', $latestRun->id)
            ->assertJsonPath('latest_run.step_count', 2)
            ->assertJsonPath('latest_run.failed_step_count', 1)
            ->assertJsonPath('runs.0.id', $latestRun->id)
            ->assertJsonPath('runs.1.id', $oldRun->id);
    }

    public function test_it_filters_router_provisioning_runs_by_status_and_time_window(): void
    {
        $failedRun = $this->createRun([
            'status' => ProvisioningRun::STATUS_FAILED,
            'progress' => 80,
            'current_stage' => 'rollback_dispatched',
            'error_message' => 'Trap encountered',
            'completed_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(20),
        ]);
        $this->createStep($failedRun, [
            'sequence' => 1,
            'status' => ProvisioningStep::STATUS_FAILED,
            'command' => '/queue simple add name=queue-test',
            'trap_message' => 'trap: interface not found',
            'error_message' => 'interface not found',
            'completed_at' => now()->subMinutes(20),
        ]);

        $completedRun = $this->createRun([
            'status' => ProvisioningRun::STATUS_COMPLETED,
            'progress' => 100,
            'current_stage' => 'completed',
            'completed_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);
        $this->createStep($completedRun, [
            'sequence' => 1,
            'stage' => 'deploy',
            'command' => '/ip address add address=10.20.0.1/24 interface=bridge1',
            'response_payload' => ['.id' => '*5'],
            'completed_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/routers/' . $this->router->id . '/provisioning-runs?' . http_build_query([
                'status' => ProvisioningRun::STATUS_FAILED,
                'from' => now()->subHour()->toIso8601String(),
                'to' => now()->subMinutes(15)->toIso8601String(),
                'per_page' => 5,
            ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('filters.status', ProvisioningRun::STATUS_FAILED)
            ->assertJsonPath('filters.per_page', 5)
            ->assertJsonPath('latest_run.id', $failedRun->id)
            ->assertJsonPath('runs.0.id', $failedRun->id)
            ->assertJsonMissingPath('runs.1.id');
    }

    public function test_it_returns_a_single_provisioning_run_with_step_history(): void
    {
        $run = $this->createRun([
            'status' => ProvisioningRun::STATUS_ROLLED_BACK,
            'progress' => 100,
            'current_stage' => 'rollback_completed',
            'error_message' => null,
            'completed_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->createStep($run, [
            'sequence' => 1,
            'stage' => 'deploy',
            'command' => '/ip pool add name=pool-test ranges=10.10.0.10-10.10.0.50',
            'response_payload' => ['.id' => '*3'],
            'completed_at' => now()->subMinutes(3),
        ]);
        $this->createStep($run, [
            'sequence' => 2,
            'stage' => 'rollback',
            'action' => 'dispatch_rollback',
            'status' => ProvisioningStep::STATUS_COMPLETED,
            'command' => '/ip pool remove numbers=*3',
            'response_payload' => ['status' => 'ok'],
            'completed_at' => now()->subMinutes(3),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/routers/' . $this->router->id . '/provisioning-runs/' . $run->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.status', ProvisioningRun::STATUS_ROLLED_BACK)
            ->assertJsonPath('run.step_count', 2)
            ->assertJsonPath('run.completed_step_count', 2)
            ->assertJsonPath('run.steps.0.sequence', 1)
            ->assertJsonPath('run.steps.1.stage', 'rollback')
            ->assertJsonPath('run.steps.1.command', '/ip pool remove numbers=*3');
    }

    public function test_it_blocks_other_tenant_access_to_provisioning_runs(): void
    {
        $otherTenant = Tenant::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-' . Str::random(6),
            'schema_name' => 'other_tenant_schema_' . Str::random(6),
            'email' => 'other@example.test',
            'is_active' => true,
            'is_default' => false,
            'is_landlord' => false,
            'schema_created' => true,
        ]);
        $otherAdmin = $this->createAdminUser($otherTenant);

        $response = $this->actingAs($otherAdmin, 'sanctum')
            ->getJson('/api/routers/' . $this->router->id . '/provisioning-runs');

        $response->assertStatus(403);
    }
}
