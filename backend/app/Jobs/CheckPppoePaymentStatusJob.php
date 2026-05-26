<?php

namespace App\Jobs;

use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\RouterTask;
use App\Models\Tenant;
use App\Services\ProvisioningServiceClient;
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

    public function __construct(?string $tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('payment-checks');
    }

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        // If no tenant ID, dispatch for all active tenants
        if (!$this->tenantId) {
            // OPTIMIZED: Select only id column
            $tenantIds = Tenant::query()
                ->select(['id'])
                ->where('is_active', true)
                ->pluck('id');
            
            foreach ($tenantIds as $tenantId) {
                self::dispatch($tenantId);
            }
            
            return;
        }

        $this->executeInTenantContext(function() {
            try {
                $gracePeriodDays = 3; // 3 days grace period
                
                // 1. Immediately block all unpaid users who are not already suspended
                // OPTIMIZED: Select only needed columns
                $unpaidUsers = PppoeUser::query()
                    ->select(['id', 'username', 'account_number', 'payment_status', 'next_payment_due', 'suspended_at', 'is_active', 'in_grace_period', 'grace_period_ends', 'router_id'])
                    ->where('payment_status', 'unpaid')
                    ->whereNull('suspended_at')
                    ->where('is_active', true)
                    ->get();

                foreach ($unpaidUsers as $user) {
                    // Check if user should be in grace period or immediately suspended
                    if ($user->next_payment_due && $user->next_payment_due->greaterThan(now())) {
                        // Payment due in future, put in grace period
                        $user->in_grace_period = true;
                        $user->grace_period_ends = $user->next_payment_due;
                        $user->save();

                        Log::info('PPPoE unpaid user put in grace period', [
                            'tenant_id' => $this->tenantId,
                            'user_id' => $user->id,
                            'username' => $user->username,
                            'next_payment_due' => $user->next_payment_due,
                        ]);
                    } else {
                        // Payment overdue or no due date, suspend immediately
                        $user->suspendForNonPayment();
                        $this->blockUserInRadius($user);
                        $this->disconnectPppoeSessions($user, $provisioningClient);

                        Log::warning('PPPoE user suspended for non-payment', [
                            'tenant_id' => $this->tenantId,
                            'user_id' => $user->id,
                            'username' => $user->username,
                            'next_payment_due' => $user->next_payment_due,
                        ]);
                    }
                }

                // 2. Check users whose grace period has expired
                // OPTIMIZED: Select only needed columns
                $expiredGraceUsers = PppoeUser::query()
                    ->select(['id', 'username', 'account_number', 'in_grace_period', 'grace_period_ends', 'suspended_at', 'router_id'])
                    ->where('in_grace_period', true)
                    ->where('grace_period_ends', '<', now())
                    ->whereNull('suspended_at')
                    ->get();

                foreach ($expiredGraceUsers as $user) {
                    // Suspend user and disconnect from RADIUS
                    $user->suspendForNonPayment();
                    
                    // Update RADIUS to reject authentication
                    $this->blockUserInRadius($user);

                    // Disconnect active PPPoE session immediately (best-effort)
                    $this->disconnectPppoeSessions($user, $provisioningClient);

                    Log::warning('PPPoE user suspended - grace period expired', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'account_number' => $user->account_number,
                        'grace_period_ends' => $user->grace_period_ends,
                    ]);
                }

                // 3. Check users who are paid but subscription expired
                // OPTIMIZED: Select only needed columns
                $expiredSubscriptions = PppoeUser::query()
                    ->select(['id', 'username', 'account_number', 'payment_status', 'expires_at', 'is_active', 'status', 'router_id'])
                    ->where('payment_status', 'paid')
                    ->where('expires_at', '<', now())
                    ->where('is_active', true)
                    ->get();

                foreach ($expiredSubscriptions as $user) {
                    // Mark as unpaid and set new payment due date
                    $user->payment_status = 'unpaid';
                    $user->next_payment_due = now()->addDays(7); // 7 days to renew
                    $user->last_payment_date = null;
                    $user->is_active = false;
                    $user->status = 'expired';
                    $user->save();

                    // Block in RADIUS and disconnect active session (best-effort)
                    $this->blockUserInRadius($user);
                    $this->disconnectPppoeSessions($user, $provisioningClient);

                    Log::info('PPPoE user subscription expired, payment required', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'account_number' => $user->account_number,
                    ]);
                }

                // 4. Auto-resume accounts whose pause period has expired
                $expiredPauses = PppoeUser::query()
                    ->select(['id', 'username', 'account_number', 'paused_at', 'pause_ends_at', 'expires_at'])
                    ->whereNotNull('paused_at')
                    ->where('pause_ends_at', '<', now())
                    ->get();

                foreach ($expiredPauses as $user) {
                    // Clear pause — no days credited back (pause window fully consumed)
                    $user->update([
                        'paused_at'     => null,
                        'pause_ends_at' => null,
                        'pause_reason'  => null,
                    ]);

                    // Remove RADIUS reject so the user can reconnect
                    DB::table('radcheck')
                        ->where('username', $user->username)
                        ->where('attribute', 'Auth-Type')
                        ->where('value', 'Reject')
                        ->delete();

                    Log::info('PPPoE paused account auto-resumed after pause expiry', [
                        'tenant_id'  => $this->tenantId,
                        'user_id'    => $user->id,
                        'username'   => $user->username,
                    ]);
                }

                // 5. Apply pending plan switches where effective_date has passed
                $pendingSwitches = PppoeUser::query()
                    ->select(['id', 'username', 'account_number', 'package_id', 'pending_package_id', 'plan_switch_effective_date', 'rate_limit'])
                    ->whereNotNull('pending_package_id')
                    ->where('plan_switch_effective_date', '<=', now())
                    ->get();

                foreach ($pendingSwitches as $user) {
                    $newPackage = \App\Models\Package::query()
                        ->select(['id', 'name', 'download_speed', 'upload_speed'])
                        ->where('id', $user->pending_package_id)
                        ->where('is_active', true)
                        ->first();

                    if (!$newPackage) {
                        Log::warning('PPPoE plan switch skipped: package not found or inactive', [
                            'user_id'    => $user->id,
                            'package_id' => $user->pending_package_id,
                        ]);
                        $user->update(['pending_package_id' => null, 'plan_switch_effective_date' => null]);
                        continue;
                    }

                    $rateLimit = $newPackage->download_speed && $newPackage->upload_speed
                        ? $newPackage->download_speed . 'M/' . $newPackage->upload_speed . 'M'
                        : $user->rate_limit;

                    $user->update([
                        'package_id'                 => $newPackage->id,
                        'pending_package_id'         => null,
                        'plan_switch_effective_date' => null,
                        'rate_limit'                 => $rateLimit,
                    ]);

                    // Update RADIUS rate-limit reply attribute
                    if ($rateLimit) {
                        DB::table('radreply')->upsert(
                            [['username' => $user->username, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $rateLimit, 'created_at' => now(), 'updated_at' => now()]],
                            ['username', 'attribute'],
                            ['op', 'value', 'updated_at']
                        );
                    }

                    Log::info('PPPoE plan switch applied', [
                        'tenant_id'       => $this->tenantId,
                        'user_id'         => $user->id,
                        'username'        => $user->username,
                        'new_package'     => $newPackage->name,
                        'new_rate_limit'  => $rateLimit,
                    ]);
                }

                Log::info('PPPoE payment status check completed', [
                    'tenant_id' => $this->tenantId,
                    'unpaid_users_processed' => $unpaidUsers->count(),
                    'grace_period_expired' => $expiredGraceUsers->count(),
                    'expired_subscriptions' => $expiredSubscriptions->count(),
                    'pauses_auto_resumed'  => $expiredPauses->count(),
                    'plan_switches_applied' => $pendingSwitches->count(),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to check PPPoE payment status', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function disconnectPppoeSessions(PppoeUser $user, ProvisioningServiceClient $provisioningClient): void
    {
        try {
            if (empty($user->router_id) || empty($user->username)) {
                return;
            }

            // OPTIMIZED: Select only needed columns
            $router = Router::query()
                ->select(['id', 'host', 'ssh_port', 'ssh_user', 'ssh_pass', 'ssh_private_key', 'ip_address', 'vpn_ip', 'username', 'password', 'port'])
                ->find($user->router_id);
            if (!$router) {
                return;
            }

            $username = (string) $user->username;
            $commands = [
                sprintf(':do { /ppp active remove [find name="%s"] } on-error={}', addslashes($username)),
                sprintf(':do { /ppp active remove [find user="%s"] } on-error={}', addslashes($username)),
            ];

            $task = RouterTask::create([
                'tenant_id' => $this->tenantId,
                'router_id' => $router->id,
                'user_id' => $user->id,
                'type' => RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                'status' => RouterTask::STATUS_QUEUED,
                'progress' => 0,
                'message' => 'Queueing PPPoE enforcement disconnect',
                'request_payload' => [
                    'context' => 'disconnect_pppoe_enforcement',
                    'action' => 'disconnect_pppoe_enforcement',
                    'username' => $username,
                    'commands' => $commands,
                ],
            ]);

            $provisioningClient->submitTaskCommand(
                $router,
                $this->tenantId,
                RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                ['commands' => $commands, 'context' => 'disconnect_pppoe_enforcement', 'action' => 'disconnect_pppoe_enforcement'],
                $task
            );

            Log::info('PPPoE session disconnect command submitted via provisioning service (best-effort)', [
                'tenant_id' => $this->tenantId,
                'user_id' => (string) $user->id,
                'username' => $username,
                'router_id' => (string) $router->id,
                'task_id' => $task->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to disconnect PPPoE sessions (best-effort)', [
                'tenant_id' => $this->tenantId,
                'user_id' => (string) ($user->id ?? ''),
                'username' => (string) ($user->username ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function blockUserInRadius(PppoeUser $user): void
    {
        try {
            // Add Auth-Type := Reject to radcheck
            DB::table('radcheck')->updateOrInsert(
                ['username' => $user->username, 'attribute' => 'Auth-Type'],
                ['op' => ':=', 'value' => 'Reject']
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
