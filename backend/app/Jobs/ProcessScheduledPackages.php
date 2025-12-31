<?php

namespace App\Jobs;

use App\Models\Package;
use App\Models\Tenant;
use App\Events\PackageStatusChanged;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessScheduledPackages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        // Set the queue this job should be dispatched to
        $this->onQueue('packages');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched scheduled packages jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            Log::info('ProcessScheduledPackages job started', ['tenant_id' => $this->tenantId]);

            try {
                // Get packages that need to be activated (Package is in public schema, but might have tenant_id)
                // Wait, if packages are in public schema, do we need to switch context?
                // The `Package` model might have `TenantScope`.
                // If we are in tenant context (which we are), `TenantScope` will filter by `tenant_id`.
                // However, `Package` is in public schema. We need to make sure we are querying correct table.
                // TenantAwareJob sets search_path to "tenant, public".
                // So querying `packages` will find it in public schema if not in tenant schema.
                
                $packagesToActivate = Package::where('enable_schedule', true)
                    ->where('scheduled_activation_time', '<=', Carbon::now())
                    ->where('status', 'inactive')
                    ->get();

                Log::info('Found packages to activate', [
                    'tenant_id' => $this->tenantId,
                    'count' => $packagesToActivate->count()
                ]);

                foreach ($packagesToActivate as $package) {
                    $this->activatePackage($package);
                }

                // Get packages that need to be deactivated
                $packagesToDeactivate = Package::where('enable_schedule', true)
                    ->whereNotNull('scheduled_deactivation_time')
                    ->where('scheduled_deactivation_time', '<=', Carbon::now())
                    ->where('status', 'active')
                    ->get();

                Log::info('Found packages to deactivate', [
                    'tenant_id' => $this->tenantId,
                    'count' => $packagesToDeactivate->count()
                ]);

                foreach ($packagesToDeactivate as $package) {
                    $this->deactivatePackage($package);
                }

                Log::info('ProcessScheduledPackages job completed successfully', ['tenant_id' => $this->tenantId]);

            } catch (\Exception $e) {
                Log::error('ProcessScheduledPackages job failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Activate a package
     */
    private function activatePackage(Package $package): void
    {
        try {
            $oldStatus = $package->status;
            
            $package->update([
                'status' => 'active',
                'is_active' => true
            ]);

            Log::info('Package activated', [
                'tenant_id' => $this->tenantId,
                'package_id' => $package->id,
                'package_name' => $package->name,
                'scheduled_time' => $package->scheduled_activation_time
            ]);

            // Broadcast event to private channel
            try {
                broadcast(new PackageStatusChanged($package, $oldStatus, 'active'))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast PackageStatusChanged event', [
                    'tenant_id' => $this->tenantId,
                    'package_id' => $package->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to activate package', [
                'tenant_id' => $this->tenantId,
                'package_id' => $package->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Deactivate a package
     */
    private function deactivatePackage(Package $package): void
    {
        try {
            $oldStatus = $package->status;
            
            $package->update([
                'status' => 'inactive',
                'is_active' => false
            ]);

            Log::info('Package deactivated', [
                'tenant_id' => $this->tenantId,
                'package_id' => $package->id,
                'package_name' => $package->name,
                'reason' => 'Scheduled validity expired'
            ]);

            // Broadcast event to private channel
            try {
                broadcast(new PackageStatusChanged($package, $oldStatus, 'inactive'))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast PackageStatusChanged event', [
                    'tenant_id' => $this->tenantId,
                    'package_id' => $package->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to deactivate package', [
                'tenant_id' => $this->tenantId,
                'package_id' => $package->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate expiry time based on validity string
     */
    private function calculateExpiryTime(Carbon $activationTime, string $validity): Carbon
    {
        $validity = strtolower(trim($validity));
        
        // Parse validity string (e.g., "1 hour", "24 hours", "30 days")
        if (preg_match('/(\d+)\s*(hour|hours|day|days|week|weeks|month|months)/', $validity, $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            switch ($unit) {
                case 'hour':
                case 'hours':
                    return $activationTime->copy()->addHours($amount);
                case 'day':
                case 'days':
                    return $activationTime->copy()->addDays($amount);
                case 'week':
                case 'weeks':
                    return $activationTime->copy()->addWeeks($amount);
                case 'month':
                case 'months':
                    return $activationTime->copy()->addMonths($amount);
            }
        }

        // Default to 30 days if parsing fails
        return $activationTime->copy()->addDays(30);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessScheduledPackages job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
