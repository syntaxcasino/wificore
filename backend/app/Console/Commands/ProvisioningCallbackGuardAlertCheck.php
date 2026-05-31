<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProvisioningCallbackGuardAlertCheck extends Command
{
    protected $signature = 'provisioning:callback-guard-alert-check
                            {--force : Ignore cooldown and emit alert log if thresholds are exceeded}';

    protected $description = 'Check provisioning callback guard 10-minute trend and emit warning/critical alerts.';

    private const ACTIONS = [
        'identity_validation_failed',
        'freshness_validation_failed',
        'terminal_status_mutation_ignored',
        'regressive_stage_ignored',
    ];

    public function handle(): int
    {
        $windowMinutes = max(1, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_WINDOW_MINUTES', 10));
        $warnThreshold = max(1, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_WARN_DELTA', 5));
        $criticalThreshold = max($warnThreshold, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_CRITICAL_DELTA', 20));
        $cooldownSeconds = max(60, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_COOLDOWN_SECONDS', 900));

        $deltas = [];
        $totalDelta = 0;

        foreach (self::ACTIONS as $action) {
            $delta = $this->computeDelta($action, $windowMinutes);
            $deltas[$action] = $delta;
            $totalDelta += $delta;
        }

        if ($totalDelta < $warnThreshold) {
            $this->line('Callback guard trend is within normal range.');
            return self::SUCCESS;
        }

        $level = $totalDelta >= $criticalThreshold ? 'critical' : 'warning';

        if (! $this->option('force') && ! $this->shouldEmitAlert($level, $totalDelta, $cooldownSeconds)) {
            $this->line('Callback guard alert suppressed by cooldown.');
            return self::SUCCESS;
        }

        Log::warning('Provisioning callback guard trend alert', [
            'level' => $level,
            'window_minutes' => $windowMinutes,
            'last_window_total_delta' => $totalDelta,
            'warn_threshold' => $warnThreshold,
            'critical_threshold' => $criticalThreshold,
            'deltas' => $deltas,
        ]);

        $this->warn(sprintf(
            'Provisioning callback guard %s trend alert: %d in last %d minute(s).',
            $level,
            $totalDelta,
            $windowMinutes
        ));

        return self::SUCCESS;
    }

    private function computeDelta(string $action, int $minutes): int
    {
        $bucketKey = 'metrics:provisioning:callback_guard:trend:' . $action;
        $buckets = Cache::get($bucketKey, []);

        if (! is_array($buckets) || empty($buckets)) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes)->format('Y-m-d\\TH:i');
        $delta = 0;

        foreach ($buckets as $bucket => $count) {
            if (is_string($bucket) && $bucket >= $cutoff) {
                $delta += (int) $count;
            }
        }

        return $delta;
    }

    private function shouldEmitAlert(string $level, int $totalDelta, int $cooldownSeconds): bool
    {
        $key = 'metrics:provisioning:callback_guard:alert_cooldown';
        $last = Cache::get($key);

        if (is_array($last)) {
            $lastAt = isset($last['at']) ? (int) $last['at'] : 0;
            if ($lastAt > 0 && (time() - $lastAt) < $cooldownSeconds) {
                return false;
            }
        }

        Cache::put($key, [
            'at' => time(),
            'level' => $level,
            'total_delta' => $totalDelta,
        ], now()->addSeconds($cooldownSeconds + 60));

        return true;
    }
}
