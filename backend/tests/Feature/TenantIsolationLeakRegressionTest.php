<?php

namespace Tests\Feature;

use App\Models\HotspotSession;
use App\Models\HotspotUser;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\RouterTenantMap;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_pgsql')]
class TenantIsolationLeakRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function switchSearchPath(string $searchPath): void
    {
        config(['database.connections.pgsql.search_path' => $searchPath]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        DB::connection()->recordsHaveBeenModified();
    }

    private function createTenantSchema(Tenant $tenant): void
    {
        $this->switchSearchPath('public');
        DB::statement('CREATE SCHEMA IF NOT EXISTS ' . $tenant->schema_name);

        $this->switchSearchPath($tenant->schema_name . ',public');
        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        DB::connection()->recordsHaveBeenModified();
    }

    private function createTenant(string $slug, string $schemaName): Tenant
    {
        $this->switchSearchPath('public');

        return Tenant::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => Str::title(str_replace('_', ' ', $slug)),
            'slug' => $slug,
            'email' => $slug . '@example.test',
            'schema_name' => $schemaName,
            'schema_created' => true,
            'is_active' => true,
            'is_default' => false,
            'is_landlord' => false,
        ]);
    }

    private function createPublicUser(Tenant $tenant, string $suffix): User
    {
        $this->switchSearchPath('public');

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant ' . $suffix . ' Admin',
            'username' => 'tenant_' . $suffix . '_admin',
            'email' => 'tenant-' . $suffix . '@example.test',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function seedTenantDataset(Tenant $tenant, User $user, string $suffix): array
    {
        return app(TenantContext::class)->runInTenantContext($tenant, function () use ($tenant, $user, $suffix): array {
            $package = Package::create([
                'id' => (string) Str::uuid(),
                'type' => 'pppoe',
                'name' => 'Tenant ' . $suffix . ' Package',
                'description' => 'Isolation regression package',
                'duration' => '30',
                'upload_speed' => '10M',
                'download_speed' => '10M',
                'price' => 100.00,
                'devices' => 1,
                'is_active' => true,
                'is_public' => true,
                'is_global' => true,
                'status' => 'active',
            ]);

            $router = Router::create([
                'id' => (string) Str::uuid(),
                'name' => 'Router ' . $suffix,
                'ip_address' => '10.0.' . ($suffix === 'a' ? '1' : '2') . '.1',
                'username' => 'admin',
                'password' => 'router-password',
                'status' => 'online',
                'vendor' => 'mikrotik',
                'device_type' => 'router',
            ]);

            $this->switchSearchPath('public');
            RouterTenantMap::updateOrCreate(
                ['router_id' => $router->id],
                [
                    'tenant_id' => $tenant->id,
                    'ip_address' => $router->ip_address,
                ]
            );
            $this->switchSearchPath($tenant->schema_name . ',public');

            $pppoeUser = PppoeUser::create([
                'id' => (string) Str::uuid(),
                'username' => 'pppoe_' . $suffix,
                'account_number' => 'ACC-' . strtoupper($suffix) . '-' . Str::upper(Str::random(6)),
                'password' => bcrypt('password'),
                'customer_name' => 'PPPoE ' . $suffix,
                'customer_phone' => '070000000' . ($suffix === 'a' ? '1' : '2'),
                'package_id' => $package->id,
                'router_id' => $router->id,
                'status' => 'active',
                'payment_status' => 'paid',
                'is_active' => true,
            ]);

            $hotspotUser = HotspotUser::create([
                'id' => (string) Str::uuid(),
                'username' => 'hotspot_' . $suffix,
                'password' => bcrypt('password'),
                'phone_number' => '071000000' . ($suffix === 'a' ? '1' : '2'),
                'package_id' => $package->id,
                'package_name' => $package->name,
                'has_active_subscription' => true,
                'is_active' => true,
                'status' => 'active',
            ]);

            $payment = Payment::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'mac_address' => 'AA:BB:CC:DD:EE:' . ($suffix === 'a' ? '01' : '02'),
                'phone_number' => '25470000000' . ($suffix === 'a' ? '1' : '2'),
                'package_id' => $package->id,
                'router_id' => $router->id,
                'amount' => 100.00,
                'transaction_id' => 'TXN-' . strtoupper($suffix) . '-' . Str::upper(Str::random(8)),
                'status' => 'completed',
                'payment_method' => 'mpesa',
            ]);

            $userSession = UserSession::create([
                'id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'voucher' => 'VCH-' . strtoupper($suffix) . '-' . Str::upper(Str::random(8)),
                'mac_address' => 'AA:BB:CC:DD:EE:' . ($suffix === 'a' ? '11' : '12'),
                'start_time' => now()->subMinutes(5),
                'end_time' => now()->addHours(1),
                'status' => 'active',
                'data_used' => 1024,
                'data_upload' => 256,
                'data_download' => 768,
            ]);

            $hotspotSession = HotspotSession::create([
                'id' => (string) Str::uuid(),
                'hotspot_user_id' => $hotspotUser->id,
                'mac_address' => 'AA:BB:CC:DD:EE:' . ($suffix === 'a' ? '21' : '22'),
                'ip_address' => '172.16.' . ($suffix === 'a' ? '1' : '2') . '.10',
                'session_start' => now()->subMinutes(10),
                'last_activity' => now()->subMinutes(1),
                'expires_at' => now()->addHour(),
                'is_active' => true,
                'bytes_uploaded' => 2048,
                'bytes_downloaded' => 4096,
                'total_bytes' => 6144,
                'device_type' => 'laptop',
            ]);

            return [
                'package_id' => $package->id,
                'router_id' => $router->id,
                'pppoe_user_id' => $pppoeUser->id,
                'pppoe_username' => $pppoeUser->username,
                'hotspot_user_id' => $hotspotUser->id,
                'hotspot_username' => $hotspotUser->username,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'user_session_id' => $userSession->id,
                'voucher' => $userSession->voucher,
                'hotspot_session_id' => $hotspotSession->id,
            ];
        });
    }

    public function test_two_tenants_remain_isolated_for_router_pppoe_hotspot_payment_and_sessions(): void
    {
        $tenantA = $this->createTenant('tenant-a', 'ts_isolation_a');
        $tenantB = $this->createTenant('tenant-b', 'ts_isolation_b');

        $this->createTenantSchema($tenantA);
        $this->createTenantSchema($tenantB);

        $userA = $this->createPublicUser($tenantA, 'a');
        $userB = $this->createPublicUser($tenantB, 'b');

        $datasetA = $this->seedTenantDataset($tenantA, $userA, 'a');
        $datasetB = $this->seedTenantDataset($tenantB, $userB, 'b');

        app(TenantContext::class)->runInTenantContext($tenantA, function () use ($datasetA, $datasetB): void {
            $this->assertSame(1, Router::query()->count());
            $this->assertSame(1, PppoeUser::query()->count());
            $this->assertSame(1, HotspotUser::query()->count());
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(1, UserSession::query()->count());
            $this->assertSame(1, HotspotSession::query()->count());

            $this->assertNotNull(Router::whereKey($datasetA['router_id'])->first());
            $this->assertNull(Router::whereKey($datasetB['router_id'])->first());

            $this->assertNotNull(PppoeUser::whereKey($datasetA['pppoe_user_id'])->first());
            $this->assertNull(PppoeUser::whereKey($datasetB['pppoe_user_id'])->first());

            $this->assertNotNull(HotspotUser::whereKey($datasetA['hotspot_user_id'])->first());
            $this->assertNull(HotspotUser::whereKey($datasetB['hotspot_user_id'])->first());

            $this->assertNotNull(Payment::whereKey($datasetA['payment_id'])->first());
            $this->assertNull(Payment::whereKey($datasetB['payment_id'])->first());

            $this->assertNotNull(UserSession::whereKey($datasetA['user_session_id'])->first());
            $this->assertNull(UserSession::whereKey($datasetB['user_session_id'])->first());

            $this->assertNotNull(HotspotSession::whereKey($datasetA['hotspot_session_id'])->first());
            $this->assertNull(HotspotSession::whereKey($datasetB['hotspot_session_id'])->first());
        });

        app(TenantContext::class)->runInTenantContext($tenantB, function () use ($datasetA, $datasetB): void {
            $this->assertSame(1, Router::query()->count());
            $this->assertSame(1, PppoeUser::query()->count());
            $this->assertSame(1, HotspotUser::query()->count());
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(1, UserSession::query()->count());
            $this->assertSame(1, HotspotSession::query()->count());

            $this->assertNull(Router::whereKey($datasetA['router_id'])->first());
            $this->assertNotNull(Router::whereKey($datasetB['router_id'])->first());

            $this->assertNull(PppoeUser::whereKey($datasetA['pppoe_user_id'])->first());
            $this->assertNotNull(PppoeUser::whereKey($datasetB['pppoe_user_id'])->first());

            $this->assertNull(HotspotUser::whereKey($datasetA['hotspot_user_id'])->first());
            $this->assertNotNull(HotspotUser::whereKey($datasetB['hotspot_user_id'])->first());

            $this->assertNull(Payment::whereKey($datasetA['payment_id'])->first());
            $this->assertNotNull(Payment::whereKey($datasetB['payment_id'])->first());

            $this->assertNull(UserSession::whereKey($datasetA['user_session_id'])->first());
            $this->assertNotNull(UserSession::whereKey($datasetB['user_session_id'])->first());

            $this->assertNull(HotspotSession::whereKey($datasetA['hotspot_session_id'])->first());
            $this->assertNotNull(HotspotSession::whereKey($datasetB['hotspot_session_id'])->first());
        });
    }
}
