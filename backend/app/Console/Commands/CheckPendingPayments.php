<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Tenant;
use App\Services\MpesaService;
use App\Services\TenantContext;
use App\Services\TenantMigrationManager;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CheckPendingPayments extends Command
{
    protected $signature = 'payments:check-pending';

    protected $description = 'Check pending M-Pesa payments using TransactionStatus API';

    public function handle(MpesaService $mpesaService, TenantContext $tenantContext, TenantMigrationManager $migrationManager): int
    {
        $tenants = Tenant::query()
            ->useWritePdo()
            ->where('is_active', true)
            ->where('schema_created', true)
            ->whereNotNull('schema_name')
            ->get(['id', 'name', 'schema_name', 'schema_created']);

        foreach ($tenants as $tenant) {
            if ($migrationManager->hasPendingMigrations($tenant)) {
                Log::warning('Tenant migrations pending; attempting tenant migration repair before payment check', [
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                    'missing_required_tables' => $migrationManager->missingRequiredTables($tenant),
                ]);

                $migrationManager->runMigrationsForTenant($tenant);
                $tenant->refresh();
            }

            if (! $migrationManager->tenantTableExists($tenant, 'payments')) {
                Log::warning('Tenant payments table missing; attempting tenant migration repair before payment check', [
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                ]);

                $migrationManager->runMigrationsForTenant($tenant);
                $tenant->refresh();

                if (! $migrationManager->tenantTableExists($tenant, 'payments')) {
                    Log::error('Tenant payments table still missing after migration repair', [
                        'tenant_id' => $tenant->id,
                        'schema_name' => $tenant->schema_name,
                        'missing_required_tables' => $migrationManager->missingRequiredTables($tenant),
                    ]);
                    continue;
                }
            }

            $tenantContext->runInTenantContext($tenant, function () use ($mpesaService, $tenant): void {
                $mpesaService->setTenantPaymentContext((string) $tenant->id);

                try {
                    Payment::query()
                        ->select(['id', 'transaction_id', 'status', 'created_at', 'callback_response'])
                        ->where('status', 'pending')
                        ->where('created_at', '<', now()->subMinutes(2))
                        ->orderBy('id')
                        ->chunkById(100, function ($pendingPayments) use ($mpesaService, $tenant): void {
                            foreach ($pendingPayments as $payment) {
                                $this->checkPendingPayment($payment, $mpesaService, $tenant);
                            }
                        });
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'relation "payments" does not exist')) {
                        Log::info('Skipping tenant pending payment check until payments table is ready', [
                            'tenant_id' => $tenant->id,
                            'schema_name' => $tenant->schema_name,
                        ]);
                        return;
                    }

                    throw $e;
                }
            });
        }

        return Command::SUCCESS;
    }

    private function checkPendingPayment(Payment $payment, MpesaService $mpesaService, Tenant $tenant): void
    {
        $this->info('Checking: ' . $payment->transaction_id);

        $response = $mpesaService->queryTransactionStatus($payment->transaction_id);

        if (!($response['success'] ?? false)) {
            Log::warning('Transaction status check failed', [
                'tenant_id' => $tenant->id,
                'transaction_id' => $payment->transaction_id,
                'response' => $response,
            ]);
            return;
        }

        $statusDesc = $response['data']['Result']['ResultDesc'] ?? 'Unknown';
        $resultCode = $response['data']['Result']['ResultCode'] ?? -1;

        if ($resultCode === 0) {
            $payment->update([
                'status' => 'completed',
                'callback_response' => $response['data'],
            ]);
            Log::info('Transaction confirmed completed', [
                'tenant_id' => $tenant->id,
                'transaction_id' => $payment->transaction_id,
            ]);
        } elseif (in_array($resultCode, [1, 1032, 1037], true)) {
            $payment->update([
                'status' => 'failed',
                'callback_response' => $response['data'],
            ]);
            Log::warning('Transaction marked as failed', [
                'tenant_id' => $tenant->id,
                'transaction_id' => $payment->transaction_id,
            ]);
        } else {
            Log::info('Transaction still pending or unclear', [
                'tenant_id' => $tenant->id,
                'transaction_id' => $payment->transaction_id,
                'result' => $statusDesc,
            ]);
        }
    }
}
