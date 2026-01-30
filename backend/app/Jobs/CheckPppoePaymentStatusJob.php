<?php

namespace App\Jobs;

use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckPppoePaymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 300;
    public $tries = 3;

    public function __construct(string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('payment-monitoring');
    }

    public function handle(): void
    {
        // If no tenant ID, dispatch for all active tenants
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            return;
        }

        $this->executeInTenantContext(function() {
            try {
                $gracePeriodDays = 3; // 3 days grace period
                
                // 1. Check users with payment overdue (not in grace period yet)
                $overdueUsers = PppoeUser::where('payment_status', 'unpaid')
                    ->where('next_payment_due', '<', now())
                    ->where('in_grace_period', false)
                    ->whereNull('suspended_at')
                    ->get();

                foreach ($overdueUsers as $user) {
                    // Put user in grace period
                    $user->in_grace_period = true;
                    $user->grace_period_ends = now()->addDays($gracePeriodDays);
                    $user->save();

                    Log::info('PPPoE user entered grace period', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'account_number' => $user->account_number,
                        'grace_period_ends' => $user->grace_period_ends,
                    ]);
                }

                // 2. Check users whose grace period has expired
                $expiredGraceUsers = PppoeUser::where('in_grace_period', true)
                    ->where('grace_period_ends', '<', now())
                    ->whereNull('suspended_at')
                    ->get();

                foreach ($expiredGraceUsers as $user) {
                    // Suspend user and disconnect from RADIUS
                    $user->suspendForNonPayment();
                    
                    // Update RADIUS to reject authentication
                    $this->blockUserInRadius($user);

                    Log::warning('PPPoE user suspended for non-payment', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'account_number' => $user->account_number,
                        'amount_due' => $user->amount_due,
                    ]);
                }

                // 3. Check users who are paid but subscription expired
                $expiredSubscriptions = PppoeUser::where('payment_status', 'paid')
                    ->where('expires_at', '<', now())
                    ->where('is_active', true)
                    ->get();

                foreach ($expiredSubscriptions as $user) {
                    // Mark as unpaid and set new payment due date
                    $user->payment_status = 'unpaid';
                    $user->next_payment_due = now()->addDays(7); // 7 days to renew
                    $user->last_payment_date = null;
                    $user->save();

                    Log::info('PPPoE user subscription expired, payment required', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'account_number' => $user->account_number,
                    ]);
                }

                Log::info('PPPoE payment status check completed', [
                    'tenant_id' => $this->tenantId,
                    'overdue_users' => $overdueUsers->count(),
                    'suspended_users' => $expiredGraceUsers->count(),
                    'expired_subscriptions' => $expiredSubscriptions->count(),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to check PPPoE payment status', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function blockUserInRadius(PppoeUser $user): void
    {
        try {
            // Add Auth-Type := Reject to radcheck
            DB::table('radcheck')->updateOrInsert(
                ['username' => $user->username, 'attribute' => 'Auth-Type'],
                ['op' => ':=', 'value' => 'Reject', 'updated_at' => now(), 'created_at' => now()]
            );

            Log::info('Blocked user in RADIUS', [
                'username' => $user->username,
                'account_number' => $user->account_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to block user in RADIUS', [
                'username' => $user->username,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
