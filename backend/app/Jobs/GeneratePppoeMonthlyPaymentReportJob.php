<?php

namespace App\Jobs;

use App\Models\PppoePayment;
use App\Models\PppoeUser;
use App\Models\Tenant;
use App\Notifications\PppoeMonthlyPaymentReportNotification;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class GeneratePppoeMonthlyPaymentReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [60, 120, 300];

    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        if (!$this->tenantId) {
            $tenants = Tenant::active()->where('schema_created', true)->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info('Dispatched PPPoE monthly payment report jobs', [
                'tenant_count' => $tenants->count(),
            ]);

            return;
        }

        $this->executeInTenantContext(function () {
            $startOfMonth = now()->subMonthNoOverflow()->startOfMonth();
            $endOfMonth = now()->subMonthNoOverflow()->endOfMonth();
            $reportMonth = $startOfMonth->format('F Y');

            $payments = PppoePayment::with('pppoeUser')
                ->completed()
                ->whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                ->orderBy('payment_date')
                ->get();

            $summary = [
                'total_payments' => $payments->count(),
                'total_amount' => (float) $payments->sum('amount'),
                'paid_accounts' => $payments->pluck('pppoe_user_id')->filter()->unique()->count(),
                'unpaid_accounts' => PppoeUser::where('payment_status', '!=', 'paid')->count(),
            ];

            $lines = [
                'report_month,account_number,username,amount,payment_method,transaction_id,payment_date,period_end',
            ];

            foreach ($payments as $payment) {
                $lines[] = implode(',', [
                    '"' . $reportMonth . '"',
                    '"' . ($payment->account_number ?? '') . '"',
                    '"' . ($payment->pppoeUser?->username ?? '') . '"',
                    '"' . number_format((float) $payment->amount, 2, '.', '') . '"',
                    '"' . ($payment->payment_method ?? '') . '"',
                    '"' . ($payment->transaction_id ?? '') . '"',
                    '"' . optional($payment->payment_date)->toDateTimeString() . '"',
                    '"' . optional($payment->period_end)->toDateString() . '"',
                ]);
            }

            $relativePath = 'reports/pppoe/' . $this->tenantId . '/pppoe-payment-report-' . $startOfMonth->format('Y-m') . '.csv';
            Storage::disk('local')->put($relativePath, implode(PHP_EOL, $lines));

            $tenant = Tenant::find($this->tenantId);
            if ($tenant?->email) {
                Notification::route('mail', $tenant->email)
                    ->notify(new PppoeMonthlyPaymentReportNotification($reportMonth, $summary, storage_path('app/' . $relativePath)));
            }

            Log::info('Generated PPPoE monthly payment report', [
                'tenant_id' => $this->tenantId,
                'report_month' => $reportMonth,
                'report_path' => $relativePath,
                'summary' => $summary,
            ]);

            event(new PppoeMonthlyReportGenerated(
                $this->tenantId,
                $reportMonth,
                $relativePath,
                (int) $summary['total_payments'],
                (float) $summary['total_amount'],
                (int) $summary['paid_accounts'],
                (int) $summary['unpaid_accounts'],
                'generate_pppoe_monthly_payment_report_job'
            ));
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('GeneratePppoeMonthlyPaymentReportJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
