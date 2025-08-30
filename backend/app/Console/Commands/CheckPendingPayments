<?php
use Illuminate\Console\Command;
use App\Models\Payment;
use App\Services\MpesaService;
use Illuminate\Support\Facades\Log;

class CheckPendingPayments extends Command
{
    protected $signature = 'payments:check-pending';
    protected $description = 'Check pending M-Pesa payments using TransactionStatus API';

    public function handle(MpesaService $mpesaService)
    {
        $pendingPayments = Payment::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(2))
            ->get();

        foreach ($pendingPayments as $payment) {
            $this->info("Checking: " . $payment->transaction_id);

            $response = $mpesaService->queryTransactionStatus($payment->transaction_id);

            if (!$response['success']) {
                Log::warning("Transaction status check failed", [
                    'transaction_id' => $payment->transaction_id,
                    'response' => $response
                ]);
                continue;
            }

            $statusDesc = $response['data']['Result']['ResultDesc'] ?? 'Unknown';
            $resultCode = $response['data']['Result']['ResultCode'] ?? -1;

            if ($resultCode == 0) {
                $payment->update([
                    'status' => 'completed',
                    'callback_response' => $response['data']
                ]);
                Log::info("Transaction confirmed completed", ['transaction_id' => $payment->transaction_id]);
            } elseif (in_array($resultCode, [1, 1032, 1037])) {
                $payment->update([
                    'status' => 'failed',
                    'callback_response' => $response['data']
                ]);
                Log::warning("Transaction marked as failed", ['transaction_id' => $payment->transaction_id]);
            } else {
                Log::info("Transaction still pending or unclear", [
                    'transaction_id' => $payment->transaction_id,
                    'result' => $statusDesc
                ]);
            }
        }

        return Command::SUCCESS;
    }
}
