<?php

namespace MrWolfGb\HillstoneFirewallSync\Jobs;

use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use MrWolfGb\HillstoneFirewallSync\Events\SyncStarted;
use MrWolfGb\HillstoneFirewallSync\Events\SyncCompleted;
use MrWolfGb\HillstoneFirewallSync\Events\SyncFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class SyncAllObjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30 seconds, 2 minutes, 5 minutes
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(2);
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'sync-all-objects';
    }

    /**
     * Execute the job.
     */
    public function handle(SyncServiceInterface $syncService): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $lockKey = 'hillstone-sync-all-objects';
        $lockTimeout = 3600; // 1 hour

        // Prevent concurrent sync operations
        if (Cache::has($lockKey)) {
            $this->logJobEvent('job_skipped_concurrent_lock', [
                'lock_key' => $lockKey,
                'reason' => 'another_sync_already_running',
            ], 'warning');
            return;
        }

        // Acquire lock
        Cache::put($lockKey, true, $lockTimeout);

        $this->logJobEvent('job_started', [
            'job_id' => $this->job->getJobId(),
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'queue' => $this->queue,
            'timeout' => $this->timeout,
            'lock_key' => $lockKey,
            'lock_timeout' => $lockTimeout,
            'memory_usage_start' => $startMemory,
        ]);

        try {
            // Check if another sync is already running
            if ($this->isSyncAlreadyRunning()) {
                $this->logJobEvent('job_cancelled_sync_in_progress', [
                    'job_id' => $this->job->getJobId(),
                    'reason' => 'database_sync_already_in_progress',
                ], 'warning');
                return;
            }

            // Execute the synchronization
            $syncStartTime = microtime(true);
            $syncLog = $syncService->syncAll();
            $syncDuration = microtime(true) - $syncStartTime;

            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);

            $this->logJobEvent('job_completed_successfully', [
                'job_id' => $this->job->getJobId(),
                'sync_log_id' => $syncLog->id,
                'objects_processed' => $syncLog->objects_processed,
                'objects_created' => $syncLog->objects_created,
                'objects_updated' => $syncLog->objects_updated,
                'objects_deleted' => $syncLog->objects_deleted,
                'sync_duration_seconds' => round($syncDuration, 3),
                'total_job_duration_seconds' => round($totalDuration, 3),
                'throughput_objects_per_second' => $syncLog->objects_processed > 0 ? round($syncLog->objects_processed / $syncDuration, 2) : 0,
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_peak' => $peakMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
                'attempt' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->logJobEvent('job_failed', [
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
                'will_retry' => $this->attempts() < $this->tries,
                'next_retry_delay' => $this->attempts() < count($this->backoff()) ? $this->backoff()[$this->attempts() - 1] : 300,
            ], 'error');

            // Release lock on failure
            Cache::forget($lockKey);

            // Re-throw to trigger retry logic
            throw $e;

        } finally {
            // Always release lock when job completes (success or final failure)
            if ($this->attempts() >= $this->tries || !$this->job->hasFailed()) {
                Cache::forget($lockKey);
                
                $this->logJobEvent('job_lock_released', [
                    'job_id' => $this->job->getJobId(),
                    'lock_key' => $lockKey,
                    'reason' => $this->attempts() >= $this->tries ? 'max_attempts_reached' : 'job_completed',
                ], 'debug');
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Sync all objects job failed permanently', [
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Create a failed sync log entry if one doesn't exist
        $this->createFailedSyncLog($exception);

        // Clean up any locks
        Cache::forget('hillstone-sync-all-objects');

        // Emit failure event
        Event::dispatch(new SyncFailed(
            $this->getOrCreateSyncLog(),
            $exception,
            [
                'type' => 'full_sync',
                'job_id' => $this->job?->getJobId(),
                'final_failure' => true,
            ]
        ));
    }

    /**
     * Check if a sync operation is already running.
     */
    private function isSyncAlreadyRunning(): bool
    {
        return SyncLog::where('status', SyncLog::STATUS_STARTED)
            ->where('operation_type', SyncLog::OPERATION_FULL_SYNC)
            ->where('started_at', '>', Carbon::now()->subHours(2))
            ->exists();
    }

    /**
     * Create a failed sync log entry.
     */
    private function createFailedSyncLog(\Throwable $exception): SyncLog
    {
        return SyncLog::create([
            'operation_type' => SyncLog::OPERATION_FULL_SYNC,
            'status' => SyncLog::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'objects_processed' => 0,
            'objects_created' => 0,
            'objects_updated' => 0,
            'objects_deleted' => 0,
        ]);
    }

    /**
     * Get or create a sync log for event dispatching.
     */
    private function getOrCreateSyncLog(): SyncLog
    {
        // Try to find the most recent sync log for this operation
        $syncLog = SyncLog::where('operation_type', SyncLog::OPERATION_FULL_SYNC)
            ->orderBy('started_at', 'desc')
            ->first();

        if (!$syncLog) {
            $syncLog = $this->createFailedSyncLog(new \Exception('Job failed before sync log creation'));
        }

        return $syncLog;
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['hillstone', 'sync', 'full-sync'];
    }

    /**
     * Log job events with structured context.
     * 
     * @param string $event
     * @param array $context
     * @param string $level
     */
    private function logJobEvent(string $event, array $context = [], string $level = 'info'): void
    {
        $config = config('hillstone.logging', []);
        
        if (!($config['log_sync_operations'] ?? true)) {
            return;
        }

        $channel = $config['channel'] ?? 'default';
        
        $logContext = array_merge([
            'service' => 'SyncAllObjectsJob',
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'job_class' => self::class,
        ], $context);

        // Add performance metrics if enabled
        if ($config['log_performance_metrics'] ?? false) {
            $logContext['memory_usage'] = memory_get_usage(true);
            $logContext['peak_memory'] = memory_get_peak_usage(true);
        }

        switch ($level) {
            case 'debug':
                Log::channel($channel)->debug("SyncAllObjectsJob: {$event}", $logContext);
                break;
            case 'info':
                Log::channel($channel)->info("SyncAllObjectsJob: {$event}", $logContext);
                break;
            case 'warning':
                Log::channel($channel)->warning("SyncAllObjectsJob: {$event}", $logContext);
                break;
            case 'error':
                Log::channel($channel)->error("SyncAllObjectsJob: {$event}", $logContext);
                break;
            default:
                Log::channel($channel)->info("SyncAllObjectsJob: {$event}", $logContext);
        }
    }
}