<?php

namespace MrWolfGb\HillstoneFirewallSync\Jobs;

use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class CleanupOldObjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of days after which objects should be considered stale.
     */
    public int $retentionDays;

    /**
     * Whether to perform a dry run (log what would be deleted without actually deleting).
     */
    public bool $dryRun;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 900; // 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(int $retentionDays = null, bool $dryRun = false)
    {
        $this->retentionDays = $retentionDays ?? Config::get('hillstone.sync.cleanup_after_days', 30);
        $this->dryRun = $dryRun;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [60, 300]; // 1 minute, 5 minutes
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHour();
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'cleanup-old-objects';
    }

    /**
     * Execute the job.
     */
    public function handle(ObjectRepositoryInterface $repository): void
    {
        $lockKey = 'hillstone-cleanup-old-objects';
        $lockTimeout = 1800; // 30 minutes

        // Prevent concurrent cleanup operations
        if (Cache::has($lockKey)) {
            Log::warning('Cleanup old objects job skipped - another cleanup is already running');
            return;
        }

        // Acquire lock
        Cache::put($lockKey, true, $lockTimeout);

        try {
            Log::info('Starting cleanup old objects job', [
                'retention_days' => $this->retentionDays,
                'dry_run' => $this->dryRun,
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'queue' => $this->queue,
            ]);

            // Calculate cutoff date
            $cutoffDate = Carbon::now()->subDays($this->retentionDays);

            // Get cleanup statistics before deletion
            $stats = $this->getCleanupStatistics($cutoffDate);

            Log::info('Cleanup statistics calculated', array_merge($stats, [
                'cutoff_date' => $cutoffDate->toISOString(),
                'dry_run' => $this->dryRun,
            ]));

            if ($this->dryRun) {
                Log::info('Dry run completed - no objects were actually deleted', $stats);
                return;
            }

            // Perform the actual cleanup if not a dry run
            $deletedCount = $this->performCleanup($repository, $cutoffDate);

            // Log cleanup completion
            Log::info('Cleanup old objects job completed successfully', [
                'job_id' => $this->job->getJobId(),
                'retention_days' => $this->retentionDays,
                'cutoff_date' => $cutoffDate->toISOString(),
                'objects_deleted' => $deletedCount,
                'hillstone_objects_deleted' => $stats['stale_hillstone_objects'],
                'hillstone_object_data_deleted' => $stats['stale_hillstone_object_data'],
                'old_sync_logs_deleted' => $stats['old_sync_logs'],
            ]);

            // Clean up old sync logs as well
            $this->cleanupOldSyncLogs($cutoffDate);

        } catch (\Exception $e) {
            Log::error('Cleanup old objects job failed', [
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'retention_days' => $this->retentionDays,
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
        Log::error('Cleanup old objects job failed permanently', [
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'retention_days' => $this->retentionDays,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Clean up any locks
        Cache::forget('hillstone-cleanup-old-objects');
    }

    /**
     * Get statistics about what would be cleaned up.
     */
    private function getCleanupStatistics(Carbon $cutoffDate): array
    {
        $staleHillstoneObjects = HillstoneObject::where('last_synced_at', '<', $cutoffDate)
            ->orWhereNull('last_synced_at')
            ->count();

        $staleHillstoneObjectData = HillstoneObjectData::where('last_synced_at', '<', $cutoffDate)
            ->orWhereNull('last_synced_at')
            ->count();

        $oldSyncLogs = SyncLog::where('started_at', '<', $cutoffDate)
            ->where('status', '!=', SyncLog::STATUS_STARTED)
            ->count();

        return [
            'stale_hillstone_objects' => $staleHillstoneObjects,
            'stale_hillstone_object_data' => $staleHillstoneObjectData,
            'old_sync_logs' => $oldSyncLogs,
            'total_objects_to_cleanup' => $staleHillstoneObjects + $staleHillstoneObjectData,
        ];
    }

    /**
     * Perform the actual cleanup operation.
     */
    private function performCleanup(ObjectRepositoryInterface $repository, Carbon $cutoffDate): int
    {
        // Use the repository's deleteStale method for consistent cleanup
        $deletedCount = $repository->deleteStale($cutoffDate);

        // Additional cleanup for objects that might not be covered by the repository
        $additionalDeleted = $this->performAdditionalCleanup($cutoffDate);

        return $deletedCount + $additionalDeleted;
    }

    /**
     * Perform additional cleanup operations not covered by the repository.
     */
    private function performAdditionalCleanup(Carbon $cutoffDate): int
    {
        $deletedCount = 0;

        // Clean up HillstoneObjectData records that might be orphaned
        $deletedObjectData = HillstoneObjectData::where('last_synced_at', '<', $cutoffDate)
            ->orWhereNull('last_synced_at')
            ->delete();

        $deletedCount += $deletedObjectData;

        Log::debug('Additional cleanup completed', [
            'deleted_object_data' => $deletedObjectData,
        ]);

        return $deletedCount;
    }

    /**
     * Clean up old sync logs.
     */
    private function cleanupOldSyncLogs(Carbon $cutoffDate): int
    {
        // Keep running sync logs, but clean up completed and failed ones
        $deletedLogs = SyncLog::where('started_at', '<', $cutoffDate)
            ->where('status', '!=', SyncLog::STATUS_STARTED)
            ->delete();

        if ($deletedLogs > 0) {
            Log::info('Old sync logs cleaned up', [
                'deleted_sync_logs' => $deletedLogs,
                'cutoff_date' => $cutoffDate->toISOString(),
            ]);
        }

        return $deletedLogs;
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        $tags = ['hillstone', 'cleanup', 'maintenance'];
        
        if ($this->dryRun) {
            $tags[] = 'dry-run';
        }
        
        return $tags;
    }
}