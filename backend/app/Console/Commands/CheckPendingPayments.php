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
                Log::info('Skipping tenant pending payment check until tenant migrations complete', [
                    'tenant_id' => $tenant->id,
                    'schema_name' => $tenant->schema_name,
                ]);
                continue;
            }

            $tenantContext->runInTenantContext($tenant, function () use ($mpesaService, $tenant): void {
                try {
                    $pendingPayments = Payment::query()
                        ->where('status', 'pending')
                        ->where('created_at', '<', now()->subMinutes(2))
                        ->get();
                } catch (QueryException $e) {
                    // Table doesn't exist yet - migrations incomplete, skip this tenant
                    if (str_contains($e->getMessage(), 'relation "payments" does not exist')) {
                        Log::warning('Skipping tenant - payments table not ready', [
                            'tenant_id' => $tenant->id,
                            'schema_name' => $tenant->schema_name,
                        ]);
                        return;
                    }
                    throw $e;
                }

                if ($pendingPayments->isEmpty()) {
                    return;
                }

                $mpesaService->setTenantPaymentContext((string) $tenant->id);

                foreach ($pendingPayments as $payment) {
                    $this->info('Checking: ' . $payment->transaction_id);

                    $response = $mpesaService->queryTransactionStatus($payment->transaction_id);

                    if (!($response['success'] ?? false)) {
                        Log::warning('Transaction status check failed', [
                            'tenant_id' => $tenant->id,
                            'transaction_id' => $payment->transaction_id,
                            'response' => $response,
                        ]);
                        continue;
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
            });
        }

        return Command::SUCCESS;
    }
}
