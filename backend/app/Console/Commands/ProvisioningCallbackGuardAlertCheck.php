<?php

namespace App\Console\Commands;

use App\Events\ProvisioningCallbackGuardAlertRaised;
use App\Models\User;
use App\Notifications\ProvisioningCallbackGuardEscalationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

    private const CRITICAL_STREAK_KEY = 'metrics:provisioning:callback_guard:critical_streak';
    private const CRITICAL_ESCALATION_AT_KEY = 'metrics:provisioning:callback_guard:critical_escalation_at';

    public function handle(): int
    {
        $windowMinutes = max(1, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_WINDOW_MINUTES', 10));
        $warnThreshold = max(1, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_WARN_DELTA', 5));
        $criticalThreshold = max($warnThreshold, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_CRITICAL_DELTA', 20));
        $cooldownSeconds = max(60, (int) env('PROVISIONING_CALLBACK_GUARD_ALERT_COOLDOWN_SECONDS', 900));
        $escalationStreakThreshold = max(1, (int) config('services.provisioning.callback_guard_escalation_consecutive_critical_checks', 3));
        $escalationCooldownSeconds = max($cooldownSeconds, (int) config('services.provisioning.callback_guard_escalation_cooldown_seconds', 3600));

        $deltas = [];
        $totalDelta = 0;

        foreach (self::ACTIONS as $action) {
            $delta = $this->computeDelta($action, $windowMinutes);
            $deltas[$action] = $delta;
            $totalDelta += $delta;
        }

        if ($totalDelta < $warnThreshold) {
            $this->resetCriticalEscalationState();
            $this->line('Callback guard trend is within normal range.');
            return self::SUCCESS;
        }

        $level = $totalDelta >= $criticalThreshold ? 'critical' : 'warning';
        $criticalStreak = $this->updateCriticalStreak($level);

        if ($level !== 'critical' && ! $this->option('force') && ! $this->shouldEmitAlert($level, $totalDelta, $cooldownSeconds)) {
            $this->line('Callback guard alert suppressed by cooldown.');
            return self::SUCCESS;
        }

        Log::warning('Provisioning callback guard trend alert', [
            'level' => $level,
            'window_minutes' => $windowMinutes,
            'last_window_total_delta' => $totalDelta,
            'warn_threshold' => $warnThreshold,
            'critical_threshold' => $criticalThreshold,
            'critical_streak' => $criticalStreak,
            'deltas' => $deltas,
        ]);

        Event::dispatch(new ProvisioningCallbackGuardAlertRaised(
            level: $level,
            windowMinutes: $windowMinutes,
            totalDelta: $totalDelta,
            warnThreshold: $warnThreshold,
            criticalThreshold: $criticalThreshold,
            deltas: $deltas
        ));

        $this->warn(sprintf(
            'Provisioning callback guard %s trend alert: %d in last %d minute(s).',
            $level,
            $totalDelta,
            $windowMinutes
        ));

        if ($level === 'critical') {
            $this->maybeEscalateCriticalAlert(
                totalDelta: $totalDelta,
                windowMinutes: $windowMinutes,
                warnThreshold: $warnThreshold,
                criticalThreshold: $criticalThreshold,
                criticalStreak: $criticalStreak,
                escalationStreakThreshold: $escalationStreakThreshold,
                escalationCooldownSeconds: $escalationCooldownSeconds,
                deltas: $deltas,
            );
        }

        return self::SUCCESS;
    }

    private function computeDelta(string $action, int $minutes): int
    {
        $bucketKey = 'metrics:provisioning:callback_guard:trend:' . $action;
        $buckets = Cache::get($bucketKey, []);

        if (! is_array($buckets) || empty($buckets)) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes)->format('Y-m-d\TH:i');
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

    private function updateCriticalStreak(string $level): int
    {
        if ($level !== 'critical') {
            $this->resetCriticalEscalationState();
            return 0;
        }

        $streak = (int) Cache::increment(self::CRITICAL_STREAK_KEY);
        Cache::put(self::CRITICAL_STREAK_KEY, $streak, now()->addHours(2));

        return $streak;
    }

    private function resetCriticalEscalationState(): void
    {
        Cache::forget(self::CRITICAL_STREAK_KEY);
        Cache::forget(self::CRITICAL_ESCALATION_AT_KEY);
    }

    private function maybeEscalateCriticalAlert(
        int $totalDelta,
        int $windowMinutes,
        int $warnThreshold,
        int $criticalThreshold,
        int $criticalStreak,
        int $escalationStreakThreshold,
        int $escalationCooldownSeconds,
        array $deltas,
    ): void {
        if ($criticalStreak < $escalationStreakThreshold) {
            return;
        }

        if ($this->isWithinEscalationCooldown($escalationCooldownSeconds)) {
            return;
        }

        $admins = User::query()
            ->where('role', 'system_admin')
            ->whereNull('tenant_id')
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        $webhookUrl = trim((string) config('services.provisioning.callback_guard_alert_webhook_url', ''));

        if ($admins->isEmpty() && $webhookUrl === '') {
            Log::warning('Provisioning callback guard escalation skipped because no notification targets are configured', [
                'critical_streak' => $criticalStreak,
                'threshold' => $escalationStreakThreshold,
            ]);
            return;
        }

        $payload = [
            'level' => 'critical',
            'window_minutes' => $windowMinutes,
            'total_delta' => $totalDelta,
            'warn_threshold' => $warnThreshold,
            'critical_threshold' => $criticalThreshold,
            'critical_streak' => $criticalStreak,
            'critical_streak_threshold' => $escalationStreakThreshold,
            'deltas' => $deltas,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new ProvisioningCallbackGuardEscalationNotification(
                    totalDelta: $totalDelta,
                    windowMinutes: $windowMinutes,
                    warnThreshold: $warnThreshold,
                    criticalThreshold: $criticalThreshold,
                    criticalStreak: $criticalStreak,
                    criticalStreakThreshold: $escalationStreakThreshold,
                    deltas: $deltas,
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send provisioning callback guard escalation notification', [
                'error' => $e->getMessage(),
                'critical_streak' => $criticalStreak,
                'critical_streak_threshold' => $escalationStreakThreshold,
            ]);
        }

        if ($webhookUrl !== '') {
            try {
                Http::timeout(5)
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'X-WifiCore-Alert-Type' => 'provisioning.callback_guard.alert',
                        'X-WifiCore-Alert-Level' => 'critical',
                    ])
                    ->post($webhookUrl, $payload);
            } catch (\Throwable $e) {
                Log::warning('Failed to send provisioning callback guard escalation webhook', [
                    'webhook_url' => $webhookUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::put(self::CRITICAL_ESCALATION_AT_KEY, now()->toIso8601String(), now()->addDay());

        Log::critical('Provisioning callback guard critical escalation dispatched', [
            'critical_streak' => $criticalStreak,
            'critical_streak_threshold' => $escalationStreakThreshold,
            'critical_threshold' => $criticalThreshold,
            'window_minutes' => $windowMinutes,
            'webhook_enabled' => $webhookUrl !== '',
            'admin_count' => $admins->count(),
        ]);
    }

    private function isWithinEscalationCooldown(int $cooldownSeconds): bool
    {
        $lastEscalationAt = Cache::get(self::CRITICAL_ESCALATION_AT_KEY);
        if (! is_string($lastEscalationAt) || trim($lastEscalationAt) === '') {
            return false;
        }

        try {
            $elapsed = now()->diffInSeconds(Carbon::parse($lastEscalationAt), false);
            return $elapsed >= 0 && $elapsed < $cooldownSeconds;
        } catch (\Throwable) {
            return false;
        }
    }
}
