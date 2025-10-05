<?php

namespace MrWolfGb\HillstoneFirewallSync\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class SyncLog extends Model
{
    protected $table = 'sync_logs';

    protected $fillable = [
        'operation_type',
        'status',
        'objects_processed',
        'objects_created',
        'objects_updated',
        'objects_deleted',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'objects_processed' => 'integer',
        'objects_created' => 'integer',
        'objects_updated' => 'integer',
        'objects_deleted' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Operation types
    const OPERATION_FULL_SYNC = 'full_sync';
    const OPERATION_PARTIAL_SYNC = 'partial_sync';
    const OPERATION_OBJECT_SYNC = 'object_sync';

    // Status types
    const STATUS_STARTED = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Create a new sync log entry.
     */
    public static function createSyncEntry(string $operationType): self
    {
        return self::create([
            'operation_type' => $operationType,
            'status' => self::STATUS_STARTED,
            'objects_processed' => 0,
            'objects_created' => 0,
            'objects_updated' => 0,
            'objects_deleted' => 0,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * Update sync status to completed.
     */
    public function markCompleted(array $stats = []): self
    {
        $this->update(array_merge([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
        ], $stats));

        return $this;
    }

    /**
     * Update sync status to failed.
     */
    public function markFailed(string $errorMessage, array $stats = []): self
    {
        $this->update(array_merge([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => Carbon::now(),
        ], $stats));

        return $this;
    }

    /**
     * Update processing statistics.
     */
    public function updateStats(array $stats): self
    {
        $this->update($stats);
        return $this;
    }

    /**
     * Get the duration of the sync operation.
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->started_at);
    }

    /**
     * Check if the sync operation is still running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_STARTED;
    }

    /**
     * Check if the sync operation completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the sync operation failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}