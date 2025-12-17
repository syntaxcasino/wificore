<?php

namespace App\Jobs;

use App\Events\LogRotationCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RotateLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 180;
    public $maxExceptions = 3;

    public function __construct()
    {
        $this->onQueue('log-rotation');
    }

    public function handle(): void
    {
        $context = [
            'job' => 'RotateLogs',
            'attempt' => $this->attempts(),
            'job_id' => $this->job->getJobId() ?? 'unknown',
        ];

        Log::withContext($context)->info('Starting log rotation job');
        event(new LogRotationCompleted(['message' => 'Log rotation started'], $context));

        try {
            $logPath = storage_path('logs');
            $logFiles = [
                'router-checks-queue.log',
                'router-checks-queue-error.log',
                'router-data-queue.log',
                'router-data-queue-error.log',
                'laravel.log',
                'mpesa_raw.log',
                'mpesa_raw_callback.log',
            ];
            $maxRotations = 7;
            $maxSize = 10 * 1024 * 1024;

            foreach ($logFiles as $logFile) {
                $fullPath = "$logPath/$logFile";

                if (!file_exists($fullPath)) {
                    Log::withContext($context)->debug('Log file not found, skipping', ['file' => $logFile]);
                    continue;
                }

                $fileSize = filesize($fullPath);
                if ($fileSize > $maxSize) {
                    $timestamp = now()->format('Ymd_His');
                    $rotatedFile = "$logPath/$logFile.$timestamp";

                    if (rename($fullPath, $rotatedFile)) {
                        Log::withContext($context)->info('Log file rotated', [
                            'original' => $logFile,
                            'rotated' => "$logFile.$timestamp",
                            'size_bytes' => $fileSize,
                        ]);
                        event(new LogRotationCompleted(['message' => 'Log file rotated'], [
                            'original' => $logFile,
                            'rotated' => "$logFile.$timestamp",
                            'size_bytes' => $fileSize,
                        ]));

                        $compressedFile = "$rotatedFile.gz";
                        $content = file_get_contents($rotatedFile);
                        file_put_contents($compressedFile, gzencode($content, 9));
                        unlink($rotatedFile);

                        Log::withContext($context)->info('Rotated file compressed', [
                            'compressed_file' => "$logFile.$timestamp.gz",
                        ]);
                        event(new LogRotationCompleted(['message' => 'Rotated file compressed'], [
                            'compressed_file' => "$logFile.$timestamp.gz",
                        ]));

                        // Create new empty log file with proper permissions
                        $this->createLogFile($fullPath, '', $context);

                        $this->cleanupOldLogs($logPath, $logFile, $maxRotations, $context);
                    } else {
                        Log::withContext($context)->error('Failed to rotate log file', [
                            'file' => $logFile,
                            'error' => error_get_last()['message'] ?? 'Unknown error',
                        ]);
                        event(new LogRotationCompleted(['message' => 'Failed to rotate log file'], [
                            'file' => $logFile,
                            'error' => error_get_last()['message'] ?? 'Unknown error',
                        ]));
                    }
                }
            }

            // Note: Queue workers will automatically restart due to --max-time parameter
            // which ensures they reopen log file handles. No need to signal supervisor.
            Log::withContext($context)->debug('Log rotation complete, workers will reopen files on next restart');

            Log::withContext($context)->info('Log rotation job completed successfully');
            event(new LogRotationCompleted(['message' => 'Log rotation job completed successfully']));
        } catch (\Throwable $e) {
            Log::withContext($context)->error('Log rotation job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            event(new LogRotationCompleted(['message' => 'Log rotation job failed'], [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    protected function cleanupOldLogs(string $logPath, string $logFile, int $maxRotations, array $context): void
    {
        $pattern = "$logPath/$logFile.*.gz";
        $rotatedFiles = glob($pattern) ?: [];
        $rotatedFiles = array_filter($rotatedFiles, 'is_file');

        if (count($rotatedFiles) > $maxRotations) {
            usort($rotatedFiles, fn($a, $b) => filemtime($a) <=> filemtime($b));
            $filesToDelete = array_slice($rotatedFiles, 0, count($rotatedFiles) - $maxRotations);

            foreach ($filesToDelete as $file) {
                if (unlink($file)) {
                    Log::withContext($context)->info('Deleted old rotated log', ['file' => $file]);
                    event(new LogRotationCompleted(['message' => 'Deleted old rotated log'], ['file' => $file]));
                } else {
                    Log::withContext($context)->warning('Failed to delete old rotated log', [
                        'file' => $file,
                        'error' => error_get_last()['message'] ?? 'Unknown error',
                    ]);
                    event(new LogRotationCompleted(['message' => 'Failed to delete old rotated log'], [
                        'file' => $file,
                        'error' => error_get_last()['message'] ?? 'Unknown error',
                    ]));
                }
            }
        }
    }

    protected function signalSupervisor(): void
    {
        try {
            // Run the command in the background to avoid blocking
            // The & at the end makes it non-blocking
            $command = '/usr/bin/supervisorctl -c /etc/supervisor/supervisord.conf signal USR2 laravel-queues:* > /dev/null 2>&1 &';
            
            Log::debug('Signaling supervisor in background', ['command' => $command]);
            
            // Execute in background - this returns immediately
            exec($command);
            
            Log::info('Supervisor signal sent (background)');
            
        } catch (\Exception $e) {
            Log::warning('Could not signal supervisor', [
                'error' => $e->getMessage(),
            ]);
            // Don't throw - this is not critical for log rotation
        }
    }

    /**
     * Create a new log file with proper permissions
     */
    protected function createLogFile(string $path, string $content, array $context): bool
    {
        try {
            $directory = dirname($path);
            
            // Ensure the directory exists and is writable
            if (!file_exists($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$directory}");
                }
                chmod($directory, 0755);
            }
            
            // Create the file with content and set permissions
            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException("Failed to write to file: {$path}");
            }
            
            // Set file permissions (rw-rw-r--)
            chmod($path, 0664);
            
            Log::withContext($context)->info('Created log file', ['file' => $path]);
            return true;
            
        } catch (\Exception $e) {
            Log::withContext($context)->error('Failed to create log file', [
                'file' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::withContext([
            'job' => 'RotateLogs',
            'attempt' => $this->attempts(),
            'job_id' => $this->job->getJobId() ?? 'unknown',
        ])->error('Log rotation job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        event(new LogRotationCompleted(['message' => 'Log rotation job failed permanently'], [
            'error' => $exception->getMessage(),
        ]));
    }
}
