<?php

namespace App\Services;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class QueueMetricsService
{
    private const SUPERVISOR_CONFIG_PATH = 'supervisor/laravel-queue.conf';

    public function getRealtimeMetrics(): array
    {
        $queues = $this->getConfiguredQueues();
        $pendingByQueue = [];
        $processingByQueue = [];
        $delayedByQueue = [];
        $oldestPendingAgeByQueue = [];

        foreach ($queues as $queue) {
            $pendingByQueue[$queue] = $this->pendingSize($queue);
            $processingByQueue[$queue] = $this->reservedSize($queue);
            $delayedByQueue[$queue] = $this->delayedSize($queue);
            $oldestPendingAgeByQueue[$queue] = $this->oldestPendingAgeSeconds($queue);
        }

        $failedByQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->pluck('count', 'queue')
            ->map(fn ($count) => (int) $count)
            ->toArray();

        $configuredWorkersByQueue = $this->getConfiguredWorkersByQueue();
        $runningWorkersByQueue = $this->getRunningWorkersByQueue();

        return [
            'pending_jobs' => array_sum($pendingByQueue),
            'processing_jobs' => array_sum($processingByQueue),
            'delayed_jobs' => array_sum($delayedByQueue),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'completed_jobs' => (int) Cache::get('queue:completed:last_hour', 0),
            'active_workers' => array_sum($runningWorkersByQueue),
            'configured_queues' => $queues,
            'configured_workers_by_queue' => $configuredWorkersByQueue,
            'workers_by_queue' => $runningWorkersByQueue,
            'pending_by_queue' => $pendingByQueue,
            'processing_by_queue' => $processingByQueue,
            'delayed_by_queue' => $delayedByQueue,
            'failed_by_queue' => $failedByQueue,
            'oldest_pending_age_by_queue' => $oldestPendingAgeByQueue,
            'oldest_pending_job_age_seconds' => $this->maxAge($oldestPendingAgeByQueue),
        ];
    }

    public function getConfiguredQueues(): array
    {
        $workerDefinitions = $this->parseSupervisorWorkerDefinitions();
        $queues = [];

        foreach ($workerDefinitions as $definition) {
            foreach ($definition['queues'] as $queue) {
                $queues[$queue] = true;
            }
        }

        $queueNames = array_keys($queues);
        sort($queueNames);

        return $queueNames;
    }

    public function getConfiguredWorkersByQueue(): array
    {
        $workerDefinitions = $this->parseSupervisorWorkerDefinitions();
        $workersByQueue = [];

        foreach ($workerDefinitions as $definition) {
            foreach ($definition['queues'] as $queue) {
                $workersByQueue[$queue] = ($workersByQueue[$queue] ?? 0) + $definition['numprocs'];
            }
        }

        ksort($workersByQueue);

        return $workersByQueue;
    }

    public function getRunningWorkersByQueue(): array
    {
        $output = [];
        $returnCode = 0;

        @exec('supervisorctl -c /etc/supervisor/supervisord.conf status 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [];
        }

        $workersByProgram = [];

        foreach ($output as $line) {
            if (! str_contains($line, 'RUNNING')) {
                continue;
            }

            if (! preg_match('/laravel-queue(?:s)?:([a-z0-9\\-]+)_[0-9]+/i', $line, $matches)) {
                continue;
            }

            $workersByProgram[$matches[1]] = ($workersByProgram[$matches[1]] ?? 0) + 1;
        }

        $definitions = $this->parseSupervisorWorkerDefinitions();
        $workersByQueue = [];

        foreach ($definitions as $programName => $definition) {
            $runningCount = $workersByProgram[$programName] ?? 0;

            if ($runningCount === 0) {
                continue;
            }

            foreach ($definition['queues'] as $queue) {
                $workersByQueue[$queue] = ($workersByQueue[$queue] ?? 0) + $runningCount;
            }
        }

        ksort($workersByQueue);

        return $workersByQueue;
    }

    private function pendingSize(string $queue): int
    {
        return $this->redisQueueMetric($queue, 'pendingSize');
    }

    private function reservedSize(string $queue): int
    {
        return $this->redisQueueMetric($queue, 'reservedSize');
    }

    private function delayedSize(string $queue): int
    {
        return $this->redisQueueMetric($queue, 'delayedSize');
    }

    private function oldestPendingAgeSeconds(string $queue): ?int
    {
        $redisQueue = $this->redisQueueConnection();

        if (! $redisQueue || ! method_exists($redisQueue, 'creationTimeOfOldestPendingJob')) {
            return null;
        }

        $timestamp = $redisQueue->creationTimeOfOldestPendingJob($queue);

        if (! is_numeric($timestamp)) {
            return null;
        }

        return max(0, now()->timestamp - (int) $timestamp);
    }

    private function redisQueueMetric(string $queue, string $method): int
    {
        $redisQueue = $this->redisQueueConnection();

        if (! $redisQueue || ! method_exists($redisQueue, $method)) {
            return 0;
        }

        return (int) $redisQueue->{$method}($queue);
    }

    private function redisQueueConnection(): ?RedisQueue
    {
        $preferredConnection = config('queue.connections.redis') ? 'redis' : config('queue.default');
        $queueConnection = Queue::connection($preferredConnection);

        if (! $queueConnection instanceof RedisQueue) {
            return null;
        }

        return $queueConnection;
    }

    private function parseSupervisorWorkerDefinitions(): array
    {
        static $definitions = null;

        if ($definitions !== null) {
            return $definitions;
        }

        $path = base_path(self::SUPERVISOR_CONFIG_PATH);

        if (! is_file($path)) {
            Log::warning('Supervisor queue config not found for queue metrics', ['path' => $path]);
            return $definitions = [];
        }

        $contents = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $definitions = [];
        $currentProgram = null;

        foreach ($contents as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\[program:(laravel-queue-[^\]]+)\]$/', $trimmed, $matches)) {
                $currentProgram = $matches[1];
                $definitions[$currentProgram] = [
                    'queues' => [],
                    'numprocs' => 1,
                ];
                continue;
            }

            if (! $currentProgram) {
                continue;
            }

            if (str_starts_with($trimmed, 'command=')
                && preg_match('/--queue=([^\\s]+)/', $trimmed, $matches)) {
                $definitions[$currentProgram]['queues'] = array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
                continue;
            }

            if (str_starts_with($trimmed, 'numprocs=')) {
                $definitions[$currentProgram]['numprocs'] = max(1, (int) substr($trimmed, strlen('numprocs=')));
            }
        }

        return $definitions;
    }

    private function maxAge(array $ages): ?int
    {
        $filtered = array_filter($ages, fn ($age) => $age !== null);

        if ($filtered === []) {
            return null;
        }

        return max($filtered);
    }
}
