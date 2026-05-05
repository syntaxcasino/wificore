<?php

namespace App\Console\Commands;

use App\Jobs\CreateHotspotUserJob;
use App\Models\HotspotUser;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Simulate end-to-end hotspot payment and user creation/reactivation
 * 
 * Usage:
 *   php artisan hotspot:simulate-payment --tenant=abc123 --phone=+254712345678 --package=hotspot-daily
 *   php artisan hotspot:simulate-payment -T abc123 -P +254712345678 --amount=100
 */
class SimulateHotspotPayment extends Command
{
    protected $signature = 'hotspot:simulate-payment
                            {--T|tenant= : Tenant slug or ID}
                            {--P|phone= : Phone number (username)}
                            {--amount=100 : Payment amount in KES}
                            {--package= : Package slug/ID (auto-detects hotspot package if not specified)}
                            {--mac= : MAC address (auto-generated if not specified)}
                            {--existing : Force use existing user (test reactivation)}
                            {--dry-run : Show what would happen without executing}';

    protected $description = 'Simulate hotspot payment and trigger auto-creation/reactivation';

    public function handle(): int
    {
        $this->info('🔥 Hotspot Payment Simulation');
        $this->info(str_repeat('=', 50));

        // Get or prompt for tenant
        $tenantSlug = $this->option('tenant') ?: $this->ask('Enter tenant slug');
        $tenant = Tenant::where('slug', $tenantSlug)->orWhere('id', $tenantSlug)->first();
        
        if (!$tenant) {
            $this->error("❌ Tenant not found: {$tenantSlug}");
            $this->line('Available tenants:');
            Tenant::select('id', 'slug', 'name')->limit(10)->get()->each(function ($t) {
                $this->line("  - {$t->slug} ({$t->name})");
            });
            return 1;
        }
        
        $this->info("✓ Tenant: {$tenant->name} ({$tenant->slug})");
        
        // Set tenant context
        tenancy()->initialize($tenant);
        config(['database.connections.tenant.search_path' => $tenant->schema_name]);
        DB::connection('tenant')->reconnect();
        
        // Get or prompt for phone number
        $phone = $this->option('phone') ?: $this->ask(
            'Enter phone number',
            '+2547' . rand(10000000, 99999999)
        );
        
        // Normalize phone
        $phone = $this->normalizePhone($phone);
        $this->info("✓ Phone: {$phone}");
        
        // Check for existing user
        $existingUser = HotspotUser::where(function ($query) use ($phone) {
            $query->where('phone_number', $phone)
                ->orWhere('username', $phone);
        })->first();
            
        if ($existingUser) {
            $this->warn("⚠️  Existing user found!");
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $existingUser->id],
                    ['Username', $existingUser->username],
                    ['Status', $existingUser->status],
                    ['Current Expiry', $existingUser->subscription_expires_at?->format('Y-m-d H:i:s') ?? 'N/A'],
                    ['Package', $existingUser->package_name ?? 'N/A'],
                    ['Data Used', number_format($existingUser->data_used / 1048576, 2) . ' MB'],
                ]
            );
            
            if (!$this->confirm('This will REACTIVATE the existing user with new expiry. Continue?')) {
                $this->info('Aborted.');
                return 0;
            }
        }
        
        // Get or auto-detect package
        $packageSlug = $this->option('package');
        if ($packageSlug) {
            $package = Package::where('slug', $packageSlug)
                ->orWhere('id', $packageSlug)
                ->first();
        } else {
            // Auto-detect first hotspot package
            $package = Package::where('type', 'hotspot')->first();
            if (!$package) {
                $package = Package::first();
            }
        }
        
        if (!$package) {
            $this->error('❌ No package found!');
            return 1;
        }
        
        $this->info("✓ Package: {$package->name} ({$package->duration})");
        
        // Get or generate MAC
        $mac = $this->option('mac') ?: $this->generateMac();
        $this->info("✓ MAC: {$mac}");
        
        // Amount
        $amount = (float) ($this->option('amount') ?: 100);
        $this->info("✓ Amount: KES {$amount}");
        
        // Dry run check
        if ($this->option('dry-run')) {
            $this->warn('\n🚫 DRY RUN - No changes made');
            $this->info('Would create payment and dispatch CreateHotspotUserJob');
            return 0;
        }
        
        // Create simulated payment
        $this->info('\n📋 Creating simulated payment...');
        
        $payment = Payment::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenant->id,
            'phone_number' => $phone,
            'mac_address' => $mac,
            'amount' => $amount,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'payment_method' => 'mpesa_simulated',
            'mpesa_receipt' => 'SIM' . rand(100000, 999999),
            'status' => 'completed',
            'payment_date' => now(),
            'transaction_id' => 'SIM_' . Str::random(12),
            'result_code' => 0,
            'result_desc' => 'Simulated success',
            'callback_processed' => true,
            'callback_processed_at' => now(),
            'is_test' => true,
        ]);
        
        $this->info("✓ Payment created: {$payment->id}");
        
        // Dispatch the job
        $this->info('\n🚀 Dispatching CreateHotspotUserJob...');
        
        CreateHotspotUserJob::dispatch(
            $payment->id,
            $package->id,
            $tenant->id
        )->onQueue('hotspot-provisioning');
        
        $this->info('✓ Job dispatched to hotspot-provisioning queue');
        
        // Wait briefly and check results
        $this->info('\n⏳ Waiting for job processing (2 seconds)...');
        sleep(2);
        
        // Refresh to check results
        $user = HotspotUser::where('phone_number', $phone)->first();
        
        if ($user) {
            $isNew = $user->created_at > now()->subMinute();
            $isReactivation = $user->updated_at > $user->created_at && !$isNew;
            
            $this->info('\n' . str_repeat('=', 50));
            if ($existingUser && $isReactivation) {
                $this->info('✅ USER REACTIVATED SUCCESSFULLY!');
            } else {
                $this->info('✅ USER CREATED SUCCESSFULLY!');
            }
            $this->info(str_repeat('=', 50));
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $user->id],
                    ['Username', $user->username],
                    ['Phone', $user->phone_number],
                    ['Status', $user->status],
                    ['Subscription Active', $user->has_active_subscription ? 'YES' : 'NO'],
                    ['Package', $user->package_name],
                    ['Expires At', $user->subscription_expires_at?->format('Y-m-d H:i:s')],
                    ['Data Limit', $user->data_limit ? number_format($user->data_limit / 1073741824, 2) . ' GB' : 'Unlimited'],
                    ['Created At', $user->created_at?->format('Y-m-d H:i:s')],
                ]
            );
            
            // Show credentials info
            $this->info('\n📱 Credentials sent via SMS:');
            $this->info("   Username: {$user->username}");
            $this->info("   Password: (check HotspotCredential table or SMS logs)");
            
            // Check RADIUS entries
            $radcheck = DB::table('radcheck')->where('username', $user->username)->count();
            $radreply = DB::table('radreply')->where('username', $user->username)->count();
            $this->info("\n🔧 RADIUS Config: {$radcheck} radcheck entries, {$radreply} radreply entries");
            
        } else {
            $this->error('\n❌ User not found after job dispatch. Check logs:');
            $this->line("   tail -f storage/logs/laravel.log | grep -i 'hotspot'");
            $this->line("   php artisan queue:work --queue=hotspot-provisioning --once");
        }
        
        $this->info('\n💡 Next steps:');
        $this->line("   1. Process job: php artisan queue:work --queue=hotspot-provisioning --once");
        $this->line("   2. View user: SELECT * FROM hotspot_users WHERE phone_number = '{$phone}';");
        $this->line("   3. View RADIUS: SELECT * FROM radcheck WHERE username = '{$phone}';");
        $this->line("   4. Simulate again to test reactivation: php artisan hotspot:simulate-payment --tenant={$tenant->slug} --phone={$phone}");
        
        tenancy()->end();
        
        return $user ? 0 : 1;
    }
    
    private function normalizePhone(string $phone): string
    {
        // Remove spaces and non-numeric except +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Ensure starts with +
        if (!str_starts_with($phone, '+')) {
            // Kenyan number conversion
            if (str_starts_with($phone, '0')) {
                $phone = '+254' . substr($phone, 1);
            } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
                $phone = '+254' . $phone;
            } else {
                $phone = '+' . $phone;
            }
        }
        
        return $phone;
    }
    
    private function generateMac(): string
    {
        $mac = [];
        for ($i = 0; $i < 6; $i++) {
            $mac[] = strtoupper(dechex(rand(0, 255)));
        }
        return implode(':', $mac);
    }
}
