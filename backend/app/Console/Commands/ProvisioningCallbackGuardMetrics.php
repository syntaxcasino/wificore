<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProvisioningCallbackGuardMetrics extends Command
{
    protected $signature = 'provisioning:callback-guard-metrics
                            {--reset : Reset all callback guard counters}';

    protected $description = 'Display or reset provisioning callback guard counters.';

    private const ACTIONS = [
        'identity_validation_failed',
        'freshness_validation_failed',
        'terminal_status_mutation_ignored',
        'regressive_stage_ignored',
    ];

    public function handle(): int
    {
        if ((bool) $this->option('reset')) {
            foreach (self::ACTIONS as $action) {
                Cache::forget($this->counterKey($action));
            }
            Cache::forget('metrics:provisioning:callback_guard:last_updated_at');
            $this->info('Provisioning callback guard counters reset.');
            return 0;
        }

        $rows = [];
        $total = 0;

        foreach (self::ACTIONS as $action) {
            $value = (int) Cache::get($this->counterKey($action), 0);
            $total += $value;
            $rows[] = [$action, (string) $value];
        }

        $this->table(['Action', 'Count'], $rows);
        $this->line('Total guard outcomes: ' . $total);
        $this->line('Last updated at: ' . (string) Cache::get('metrics:provisioning:callback_guard:last_updated_at', 'N/A'));

        return 0;
    }

    private function counterKey(string $action): string
    {
        return 'metrics:provisioning:callback_guard:' . $action;
    }
}
