<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\DataUsageLog;
use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncRadiusAccountingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 2;
    public $timeout = 120;

    private const BATCH_SIZE = 100;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('hotspot-accounting');
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

            Log::info('Dispatched radius accounting sync jobs for ' . $tenants->count() . ' tenants');
            return;
        }

        $this->executeInTenantContext(function () {
            Log::info('Syncing RADIUS accounting data...', ['tenant_id' => $this->tenantId]);

            $this->syncHotspotAccounting();
            $this->syncPppoeAccounting();
        });
    }

    /**
     * Sync active hotspot sessions from radacct using batched queries.
     */
    private function syncHotspotAccounting(): void
    {
        $syncedCount = 0;
        $errorCount = 0;
        $totalSessions = 0;
        $hasSessions = false;

        RadiusSession::query()
            ->select(['id', 'hotspot_user_id', 'username', 'status'])
            ->with(['hotspotUser:id,data_limit'])
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($sessions) use (&$syncedCount, &$errorCount, &$totalSessions, &$hasSessions) {
                $hasSessions = true;

                $usernames = $sessions->pluck('username')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (empty($usernames)) {
                    return;
                }

                $latestByUsername = $this->loadLatestRadacctByUsername($usernames);

                foreach ($sessions as $session) {
                    $totalSessions++;

                    try {
                        $radacct = $latestByUsername[(string) $session->username] ?? null;

                        if (!$radacct) {
                            continue;
                        }

                        $bytesIn = (int) ($radacct->acctinputoctets ?? 0);
                        $bytesOut = (int) ($radacct->acctoutputoctets ?? 0);
                        $totalBytes = $bytesIn + $bytesOut;

                        $session->update([
                            'radacct_id' => $radacct->radacctid,
                            'bytes_in' => $bytesIn,
                            'bytes_out' => $bytesOut,
                            'total_bytes' => $totalBytes,
                            'duration_seconds' => $radacct->acctsessiontime ?? 0,
                            'ip_address' => $radacct->framedipaddress,
                            'nas_ip_address' => $radacct->nasipaddress,
                        ]);

                        $hotspotUser = $session->hotspotUser;
                        if ($hotspotUser) {
                            $hotspotUser->update([
                                'data_used' => $totalBytes,
                            ]);

                            if ($hotspotUser->data_limit && $totalBytes >= $hotspotUser->data_limit) {
                                Log::warning('Data limit exceeded', [
                                    'tenant_id' => $this->tenantId,
                                    'session_id' => $session->id,
                                    'username' => $session->username,
                                    'data_used' => $totalBytes,
                                    'data_limit' => $hotspotUser->data_limit,
                                ]);

                                DisconnectHotspotUserJob::dispatch(
                                    $session->id,
                                    $this->tenantId,
                                    'Data limit exceeded'
                                )->onQueue('hotspot-sessions')->afterCommit();
                            }
                        }

                        DataUsageLog::create([
                            'hotspot_user_id' => $session->hotspot_user_id,
                            'radius_session_id' => $session->id,
                            'bytes_in' => $bytesIn,
                            'bytes_out' => $bytesOut,
                            'total_bytes' => $totalBytes,
                            'recorded_at' => now(),
                            'source' => 'radius_accounting',
                        ]);

                        $syncedCount++;
                    } catch (\Throwable $e) {
                        $errorCount++;
                        Log::error('Failed to sync session accounting', [
                            'tenant_id' => $this->tenantId,
                            'session_id' => $session->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if (!$hasSessions) {
            Log::info('No active sessions to sync', ['tenant_id' => $this->tenantId]);
            return;
        }

        Log::info('Finished syncing RADIUS accounting (hotspot)', [
            'tenant_id' => $this->tenantId,
            'total_sessions' => $totalSessions,
            'synced' => $syncedCount,
            'errors' => $errorCount,
        ]);
    }

    /**
     * Sync active PPPoE users from radacct using batched aggregation.
     */
    private function syncPppoeAccounting(): void
    {
        $ppSynced = 0;
        $ppErrors = 0;
        $totalUsers = 0;
        $hasUsers = false;

        PppoeUser::query()
            ->select(['id', 'username', 'status'])
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($pppoeUsers) use (&$ppSynced, &$ppErrors, &$totalUsers, &$hasUsers) {
                $hasUsers = true;

                $usernames = $pppoeUsers->pluck('username')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (empty($usernames)) {
                    return;
                }

                $usageByUsername = $this->loadUsageByUsername($usernames);

                foreach ($pppoeUsers as $ppUser) {
                    $totalUsers++;

                    try {
                        $usage = $usageByUsername[(string) $ppUser->username] ?? null;

                        if (!$usage) {
                            continue;
                        }

                        $totalBytes = ((int) $usage->bytes_in) + ((int) $usage->bytes_out);
                        $ppUser->update(['data_used' => $totalBytes]);

                        $ppSynced++;
                    } catch (\Throwable $e) {
                        $ppErrors++;
                        Log::error('Failed to sync PPPoE user accounting', [
                            'tenant_id' => $this->tenantId,
                            'username' => $ppUser->username,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if (!$hasUsers) {
            Log::info('No active PPPoE users to sync', ['tenant_id' => $this->tenantId]);
            return;
        }

        Log::info('Finished syncing RADIUS accounting (pppoe)', [
            'tenant_id' => $this->tenantId,
            'pppoe_users' => $totalUsers,
            'synced' => $ppSynced,
            'errors' => $ppErrors,
        ]);
    }

    /**
     * Load the latest active radacct row for each username in a batch.
     */
    private function loadLatestRadacctByUsername(array $usernames): array
    {
        $rows = DB::table('radacct')
            ->whereIn('username', $usernames)
            ->whereNull('acctstoptime')
            ->orderBy('username')
            ->orderByDesc('acctstarttime')
            ->get([
                'radacctid',
                'username',
                'acctinputoctets',
                'acctoutputoctets',
                'acctsessiontime',
                'framedipaddress',
                'nasipaddress',
            ]);

        $latest = [];
        foreach ($rows as $row) {
            $username = (string) $row->username;
            if (!isset($latest[$username])) {
                $latest[$username] = $row;
            }
        }

        return $latest;
    }

    /**
     * Load aggregated usage totals for each username in a batch.
     */
    private function loadUsageByUsername(array $usernames): array
    {
        $rows = DB::table('radacct')
            ->whereIn('username', $usernames)
            ->selectRaw('username, COALESCE(SUM(acctinputoctets), 0) AS bytes_in, COALESCE(SUM(acctoutputoctets), 0) AS bytes_out')
            ->groupBy('username')
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(string) $row->username] = $row;
        }

        return $usage;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncRadiusAccountingJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
