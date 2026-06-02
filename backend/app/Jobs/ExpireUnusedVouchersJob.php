<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Voucher;
use App\Events\VoucherUpdated;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled job to check for unused vouchers whose expiry date has passed
 * and mark them as expired.
 *
 * Runs every minute via Laravel scheduler.
 * Tenant-aware: iterates all active tenants.
 */
class ExpireUnusedVouchersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 1;
    public $timeout = 120;

    public function __construct(?string $tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('voucher-expiry');
    }

    public function handle(): void
    {
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)
                ->where('schema_created', true)
                ->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info("Dispatched ExpireUnusedVouchersJob for {$tenants->count()} tenants");
            return;
        }

        $this->executeInTenantContext(function () {
            $now = now();

            $supportsArchiving = Schema::hasColumn('vouchers', 'archived_at');

            $expiredVouchers = Voucher::query()
                ->select(['id', 'code', 'status', 'expires_at', 'package_id'])
                ->where('status', 'unused')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->get();

            if ($expiredVouchers->isEmpty()) {
                return;
            }

            $expiredCount = 0;

            foreach ($expiredVouchers as $voucher) {
                try {
                    $update = [
                        'status' => 'expired',
                    ];

                    if ($supportsArchiving) {
                        $update['archived_at'] = now();
                    }

                    $voucher->update($update);

                    broadcast(new VoucherUpdated($voucher->fresh(), $this->tenantId))->toOthers();

                    Log::info('Voucher auto-expired and archived', [
                        'voucher_id' => $voucher->id,
                        'code' => $voucher->code,
                        'expires_at' => $voucher->expires_at,
                        'tenant_id' => $this->tenantId,
                    ]);

                    $expiredCount++;
                } catch (\Exception $e) {
                    Log::error('Failed to auto-expire voucher', [
                        'voucher_id' => $voucher->id,
                        'code' => $voucher->code,
                        'error' => $e->getMessage(),
                        'tenant_id' => $this->tenantId,
                    ]);
                }
            }

            // Bust stats cache so UI reflects updated counts
            Cache::forget("voucher_stats_tenant_{$this->tenantId}");

            Log::info('ExpireUnusedVouchersJob completed for tenant', [
                'tenant_id' => $this->tenantId,
                'expired_count' => $expiredCount,
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExpireUnusedVouchersJob failed', [
            'error' => $exception->getMessage(),
            'tenant_id' => $this->tenantId ?? 'all',
        ]);
    }
}
