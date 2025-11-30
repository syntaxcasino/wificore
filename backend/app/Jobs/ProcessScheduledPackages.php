<?php

namespace App\Jobs;

use App\Models\Package;
use App\Events\PackageStatusChanged;
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
    public function __construct()
    {
        // Set the queue this job should be dispatched to
        $this->onQueue('packages');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessScheduledPackages job started');

        try {
            // Get packages that need to be activated
            $packagesToActivate = Package::where('enable_schedule', true)
                ->where('scheduled_activation_time', '<=', Carbon::now())
                ->where('status', 'inactive')
                ->get();

            Log::info('Found packages to activate', [
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
                'count' => $packagesToDeactivate->count()
            ]);

            foreach ($packagesToDeactivate as $package) {
                $this->deactivatePackage($package);
            }

            Log::info('ProcessScheduledPackages job completed successfully');

        } catch (\Exception $e) {
            Log::error('ProcessScheduledPackages job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
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
                'package_id' => $package->id,
                'package_name' => $package->name,
                'scheduled_time' => $package->scheduled_activation_time
            ]);

            // Broadcast event to private channel
            try {
                broadcast(new PackageStatusChanged($package, $oldStatus, 'active'))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast PackageStatusChanged event', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to activate package', [
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
                'package_id' => $package->id,
                'package_name' => $package->name,
                'reason' => 'Scheduled validity expired'
            ]);

            // Broadcast event to private channel
            try {
                broadcast(new PackageStatusChanged($package, $oldStatus, 'inactive'))->toOthers();
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast PackageStatusChanged event', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to deactivate package', [
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
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
