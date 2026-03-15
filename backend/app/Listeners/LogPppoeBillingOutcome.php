<?php

namespace App\Listeners;

use App\Events\PppoeInvoiceSent;
use App\Events\PppoeMonthlyReportGenerated;
use App\Events\PppoeReminderSent;
use Illuminate\Support\Facades\Log;

class LogPppoeBillingOutcome
{
    public function handle(object $event): void
    {
        if ($event instanceof PppoeReminderSent) {
            Log::info('PPPoE billing outcome reminder sent', [
                'tenant_id' => $event->tenantId,
                'pppoe_user_id' => $event->pppoeUserId,
                'billing_email' => $event->billingEmail,
                'billing_phone' => $event->billingPhone,
                'next_payment_due' => $event->nextPaymentDue,
                'source' => $event->source,
            ]);
            return;
        }

        if ($event instanceof PppoeInvoiceSent) {
            Log::info('PPPoE billing outcome invoice sent', [
                'tenant_id' => $event->tenantId,
                'pppoe_user_id' => $event->pppoeUserId,
                'billing_email' => $event->billingEmail,
                'next_payment_due' => $event->nextPaymentDue,
                'source' => $event->source,
            ]);
            return;
        }

        if ($event instanceof PppoeMonthlyReportGenerated) {
            Log::info('PPPoE billing outcome monthly report generated', [
                'tenant_id' => $event->tenantId,
                'report_month' => $event->reportMonth,
                'report_path' => $event->reportPath,
                'total_payments' => $event->totalPayments,
                'total_amount' => $event->totalAmount,
                'paid_accounts' => $event->paidAccounts,
                'unpaid_accounts' => $event->unpaidAccounts,
                'source' => $event->source,
            ]);
        }
    }
}
