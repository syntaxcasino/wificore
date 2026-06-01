<?php

namespace App\Jobs;

use App\Services\TenantHealthScoreEngine;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateTenantHealthScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 90;

    public function __construct(?string $tenantId = null, private array $context = [])
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('dashboard');
    }

    public function handle(TenantHealthScoreEngine $engine): void
    {
        if (! $this->tenantId) {
            return;
        }

        $this->executeInTenantContext(function () use ($engine) {
            try {
                $report = $engine->buildReport($this->context);
                $snapshot = $engine->persistSnapshot((string) $this->tenantId, $report);

                Cache::put($this->cacheKey(), [
                    'id' => $snapshot->id,
                    'score' => $snapshot->score,
                    'grade' => $snapshot->grade,
                    'factors' => $snapshot->factors,
                    'signals' => $snapshot->signals,
                    'calculated_at' => optional($snapshot->calculated_at)->toIso8601String(),
                ], now()->addMinutes(5));

                Log::info('Tenant health score recalculated', [
                    'tenant_id' => $this->tenantId,
                    'score' => $snapshot->score,
                    'grade' => $snapshot->grade,
                    'source_event' => $snapshot->source_event,
                ]);
            } catch (\Throwable $e) {
                Log::error('Tenant health score recalculation failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'context' => $this->context,
                ]);
                throw $e;
            }
        });
    }

    private function cacheKey(): string
    {
        return 'tenant_health_score_latest:' . (string) $this->tenantId;
    }
}
