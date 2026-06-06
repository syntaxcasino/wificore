<?php

namespace App\Services\Deployment;

use App\Models\ConfigSnapshot;
use App\Models\Router;
use App\Models\RouterComplianceSnapshot;
use Illuminate\Support\Facades\Log;

class RouterComplianceEngine
{
    public function __construct(
        private readonly ConfigDriftDetector $driftDetector,
    ) {
    }

    public function evaluate(Router $router, ?ConfigSnapshot $baseline = null): RouterComplianceReport
    {
        $baseline = $baseline ?? $this->driftDetector->getLatestSnapshot($router);
        $configText = (string) ($baseline?->config_text ?? '');
        $checks = [];
        $passedControls = [];
        $missingControls = [];
        $score = 0;

        foreach ($this->definedChecks() as $check) {
            $passed = $this->checkPattern($configText, $check['patterns']);
            if ($check['key'] === 'baseline_snapshot') {
                $passed = $baseline !== null && trim($configText) !== '';
            }

            if ($passed) {
                $score += $check['weight'];
                $passedControls[] = $check['key'];
            } else {
                $missingControls[] = $check['label'];
            }

            $checks[] = [
                'key' => $check['key'],
                'label' => $check['label'],
                'weight' => $check['weight'],
                'passed' => $passed,
                'evidence' => [
                    'baseline_snapshot_id' => $baseline?->id,
                    'matched_patterns' => array_values(array_filter(
                        $check['patterns'],
                        fn ($pattern) => str_contains(strtolower($configText), strtolower((string) $pattern))
                    )),
                ],
            ];
        }

        $score = min(100, max(0, $score));
        $grade = $this->gradeForScore($score);
        $status = $score >= (int) config('router_compliance.minimum_score', 85)
            ? 'compliant'
            : ($score >= 70 ? 'warning' : 'non_compliant');

        if ($baseline === null) {
            $missingControls[] = 'Baseline config snapshot';
        }

        $summary = $baseline
            ? sprintf('Compliance score %d/100 based on %d checks and snapshot %s.', $score, count($checks), $baseline->id)
            : sprintf('Compliance score %d/100 with no baseline snapshot available.', $score);

        return new RouterComplianceReport(
            routerId: (string) $router->id,
            tenantId: isset($router->tenant_id) ? (string) $router->tenant_id : null,
            score: $score,
            grade: $grade,
            status: $status,
            checks: $checks,
            missingControls: array_values(array_unique($missingControls)),
            passedControls: $passedControls,
            summary: $summary,
            sourceSnapshotId: $baseline?->id ? (string) $baseline->id : null,
            evaluatedAt: now(),
        );
    }

    public function evaluateAndPersist(Router $router, ?ConfigSnapshot $baseline = null): RouterComplianceSnapshot
    {
        $report = $this->evaluate($router, $baseline);

        $snapshot = RouterComplianceSnapshot::create([
            'tenant_id' => $report->tenantId,
            'router_id' => $report->routerId,
            'score' => $report->score,
            'grade' => $report->grade,
            'status' => $report->status,
            'checks' => $report->checks,
            'missing_controls' => $report->missingControls,
            'passed_controls' => $report->passedControls,
            'summary' => $report->summary,
            'source_snapshot_id' => $report->sourceSnapshotId,
            'evaluated_at' => $report->evaluatedAt ?? now(),
        ]);

        Log::info('Router compliance snapshot stored', [
            'router_id' => $router->id,
            'tenant_id' => $report->tenantId,
            'score' => $report->score,
            'grade' => $report->grade,
            'status' => $report->status,
            'snapshot_id' => $snapshot->id,
        ]);

        return $snapshot;
    }

    public function getLatestSnapshot(Router $router): ?RouterComplianceSnapshot
    {
        return RouterComplianceSnapshot::where('router_id', $router->id)
            ->latest('evaluated_at')
            ->first();
    }

    private function definedChecks(): array
    {
        $weights = config('router_compliance.weights', []);

        return [
            ['key' => 'ssh', 'label' => 'SSH management access enabled', 'weight' => (int) ($weights['ssh'] ?? 15), 'patterns' => ['/ip service', 'name=ssh', 'disabled=no']],
            ['key' => 'api', 'label' => 'API service enabled', 'weight' => (int) ($weights['api'] ?? 15), 'patterns' => ['/ip service', 'name=api', 'disabled=no']],
            ['key' => 'firewall', 'label' => 'Firewall baseline present', 'weight' => (int) ($weights['firewall'] ?? 20), 'patterns' => ['/ip firewall filter', '/ip firewall nat']],
            ['key' => 'ntp', 'label' => 'NTP configured', 'weight' => (int) ($weights['ntp'] ?? 15), 'patterns' => ['/system ntp', 'ntp client', 'time-zone-name']],
            ['key' => 'dns', 'label' => 'DNS configured', 'weight' => (int) ($weights['dns'] ?? 10), 'patterns' => ['/ip dns set', 'servers=']],
            ['key' => 'backup_schedule', 'label' => 'Backup schedule configured', 'weight' => (int) ($weights['backup_schedule'] ?? 15), 'patterns' => ['/system scheduler', 'daily-backup', 'backup save']],
            ['key' => 'baseline_snapshot', 'label' => 'Baseline snapshot available', 'weight' => (int) ($weights['baseline_snapshot'] ?? 10), 'patterns' => []],
        ];
    }

    private function checkPattern(string $configText, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! str_contains(strtolower($configText), strtolower((string) $pattern))) {
                return false;
            }
        }

        return true;
    }

    private function gradeForScore(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            default => 'D',
        };
    }
}
