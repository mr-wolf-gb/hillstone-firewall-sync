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

class SyncSpecificObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The name of the object to synchronize.
     */
    public string $objectName;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(string $objectName)
    {
        $this->objectName = $objectName;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [10, 30, 60]; // 10 seconds, 30 seconds, 1 minute
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30);
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'sync-object-' . md5($this->objectName);
    }

    /**
     * Execute the job.
     */
    public function handle(SyncServiceInterface $syncService): void
    {
        $lockKey = 'hillstone-sync-object-' . md5($this->objectName);
        $lockTimeout = 600; // 10 minutes

        // Validate object name
        if (empty($this->objectName) || !$this->isValidObjectName($this->objectName)) {
            Log::error('Sync specific object job failed - invalid object name', [
                'object_name' => $this->objectName,
                'job_id' => $this->job->getJobId(),
            ]);
            
            $this->fail(new \InvalidArgumentException("Invalid object name: {$this->objectName}"));
            return;
        }

        // Prevent concurrent sync operations for the same object
        if (Cache::has($lockKey)) {
            Log::warning('Sync specific object job skipped - sync already running for this object', [
                'object_name' => $this->objectName,
                'job_id' => $this->job->getJobId(),
            ]);
            return;
        }

        // Acquire lock
        Cache::put($lockKey, true, $lockTimeout);

        try {
            Log::info('Starting sync specific object job', [
                'object_name' => $this->objectName,
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'queue' => $this->queue,
            ]);

            // Check if another sync for this object is already running
            if ($this->isObjectSyncAlreadyRunning()) {
                Log::warning('Sync specific object job cancelled - sync already in progress for this object', [
                    'object_name' => $this->objectName,
                ]);
                return;
            }

            // Execute the synchronization
            $syncLog = $syncService->syncSpecific($this->objectName);

            Log::info('Sync specific object job completed successfully', [
                'object_name' => $this->objectName,
                'job_id' => $this->job->getJobId(),
                'sync_log_id' => $syncLog->id,
                'objects_processed' => $syncLog->objects_processed,
                'objects_created' => $syncLog->objects_created,
                'objects_updated' => $syncLog->objects_updated,
                'duration' => $syncLog->duration,
            ]);

        } catch (\Exception $e) {
            Log::error('Sync specific object job failed', [
                'object_name' => $this->objectName,
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Release lock on failure
            Cache::forget($lockKey);

            // Re-throw to trigger retry logic
            throw $e;

        } finally {
            // Always release lock when job completes (success or final failure)
            if ($this->attempts() >= $this->tries || !$this->job->hasFailed()) {
                Cache::forget($lockKey);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Sync specific object job failed permanently', [
            'object_name' => $this->objectName,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Create a failed sync log entry if one doesn't exist
        $syncLog = $this->createFailedSyncLog($exception);

        // Clean up any locks
        Cache::forget('hillstone-sync-object-' . md5($this->objectName));

        // Emit failure event
        Event::dispatch(new SyncFailed(
            $syncLog,
            $exception,
            [
                'type' => 'object_sync',
                'object_name' => $this->objectName,
                'job_id' => $this->job?->getJobId(),
                'final_failure' => true,
            ]
        ));

        // Optionally notify administrators about the failure
        $this->notifyAdministrators($exception);
    }

    /**
     * Check if a sync operation for this specific object is already running.
     */
    private function isObjectSyncAlreadyRunning(): bool
    {
        return SyncLog::where('status', SyncLog::STATUS_STARTED)
            ->where('operation_type', SyncLog::OPERATION_OBJECT_SYNC)
            ->where('started_at', '>', Carbon::now()->subMinutes(30))
            ->exists();
    }

    /**
     * Validate the object name format.
     */
    private function isValidObjectName(string $objectName): bool
    {
        // Basic validation - object name should not be empty and should contain valid characters
        return !empty(trim($objectName)) && 
               strlen($objectName) <= 255 && 
               preg_match('/^[a-zA-Z0-9_\-\.\/\s]+$/', $objectName);
    }

    /**
     * Create a failed sync log entry.
     */
    private function createFailedSyncLog(\Throwable $exception): SyncLog
    {
        return SyncLog::create([
            'operation_type' => SyncLog::OPERATION_OBJECT_SYNC,
            'status' => SyncLog::STATUS_FAILED,
            'error_message' => "Failed to sync object '{$this->objectName}': " . $exception->getMessage(),
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'objects_processed' => 0,
            'objects_created' => 0,
            'objects_updated' => 0,
            'objects_deleted' => 0,
        ]);
    }

    /**
     * Notify administrators about job failure.
     */
    private function notifyAdministrators(\Throwable $exception): void
    {
        // This could be extended to send emails, Slack notifications, etc.
        Log::critical('Sync specific object job failed permanently - administrator notification', [
            'object_name' => $this->objectName,
            'error' => $exception->getMessage(),
            'job_id' => $this->job?->getJobId(),
            'timestamp' => Carbon::now()->toISOString(),
        ]);

        // You could dispatch additional notification jobs here
        // For example: NotifyAdministratorJob::dispatch($this->objectName, $exception);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['hillstone', 'sync', 'object-sync', $this->objectName];
    }
}