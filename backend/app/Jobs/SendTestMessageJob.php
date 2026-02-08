<?php

namespace App\Jobs;

use App\Events\TestMessageSent;
use App\Models\CommunicationChannel;
use App\Services\MessagingService;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTestMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public int $channelId;
    public string $recipient;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(int $channelId, string $recipient, $tenantId)
    {
        $this->channelId = $channelId;
        $this->recipient = $recipient;
        $this->setTenantContext($tenantId);
        $this->onQueue('messaging');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            $channel = CommunicationChannel::find($this->channelId);

            if (!$channel) {
                Log::error('SendTestMessageJob: Channel not found', [
                    'channel_id' => $this->channelId,
                    'tenant_id' => $this->tenantId,
                ]);
                event(new TestMessageSent($this->channelId, 'failed', 'Channel not found', $this->tenantId));
                return;
            }

            try {
                $service = new MessagingService();
                $result = $service->sendTestMessage($channel, $this->recipient);

                $channel->update([
                    'last_tested_at' => now(),
                    'last_test_status' => $result['success'] ? 'success' : 'failed',
                ]);

                event(new TestMessageSent(
                    $this->channelId,
                    $result['success'] ? 'success' : 'failed',
                    $result['message'],
                    $this->tenantId
                ));

                Log::info('SendTestMessageJob completed', [
                    'channel_id' => $this->channelId,
                    'tenant_id' => $this->tenantId,
                    'status' => $result['success'] ? 'success' : 'failed',
                ]);
            } catch (\Exception $e) {
                Log::error('SendTestMessageJob failed', [
                    'channel_id' => $this->channelId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                $channel->update([
                    'last_tested_at' => now(),
                    'last_test_status' => 'failed',
                ]);

                event(new TestMessageSent(
                    $this->channelId,
                    'failed',
                    'Test failed: ' . $e->getMessage(),
                    $this->tenantId
                ));
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTestMessageJob failed permanently', [
            'channel_id' => $this->channelId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
