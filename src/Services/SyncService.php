<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use MrWolfGb\HillstoneFirewallSync\Events\SyncStarted;
use MrWolfGb\HillstoneFirewallSync\Events\SyncCompleted;
use MrWolfGb\HillstoneFirewallSync\Events\SyncFailed;
use MrWolfGb\HillstoneFirewallSync\Events\ObjectSynced;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Collection;

class SyncService implements SyncServiceInterface
{
    protected HillstoneClientInterface $client;
    protected ObjectRepositoryInterface $repository;
    protected array $config;
    protected string $logChannel;

    public function __construct(
        HillstoneClientInterface $client,
        ObjectRepositoryInterface $repository,
        array $config = []
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->config = array_merge([
            'batch_size' => 50,
            'conflict_resolution' => 'latest_wins',
            'cleanup_stale' => true,
            'cleanup_after_days' => 30,
            'logging' => [
                'channel' => 'default',
                'log_sync_operations' => true,
                'log_performance_metrics' => false,
            ],
        ], $config);
        
        $this->logChannel = $this->config['logging']['channel'] ?? 'default';
        
        $this->logServiceEvent('service_initialized', [
            'batch_size' => $this->config['batch_size'],
            'conflict_resolution' => $this->config['conflict_resolution'],
            'cleanup_stale' => $this->config['cleanup_stale'],
        ]);
    }

    /**
     * Synchronize all firewall objects from the API to the database.
     * 
     * @return SyncLog The sync operation log entry
     */
    public function syncAll(): SyncLog
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $syncLog = SyncLog::createSyncEntry(SyncLog::OPERATION_FULL_SYNC);
        
        $this->logServiceEvent('sync_all_started', [
            'sync_id' => $syncLog->id,
            'batch_size' => $this->config['batch_size'],
            'cleanup_stale' => $this->config['cleanup_stale'],
            'memory_usage_start' => $startMemory,
        ]);
        
        try {
            // Emit sync started event
            Event::dispatch(new SyncStarted($syncLog, ['type' => 'full_sync']));

            // Ensure authentication
            $authStartTime = microtime(true);
            if (!$this->ensureAuthenticated()) {
                throw new \Exception('Failed to authenticate with Hillstone API');
            }
            $authDuration = microtime(true) - $authStartTime;
            
            $this->logServiceEvent('authentication_completed', [
                'sync_id' => $syncLog->id,
                'duration_seconds' => round($authDuration, 3),
            ]);

            // Get all objects from API
            $apiStartTime = microtime(true);
            $apiObjects = $this->client->getAllAddressBookObjects();
            $apiDuration = microtime(true) - $apiStartTime;
            
            if (!$apiObjects instanceof Collection) {
                $apiObjects = collect($apiObjects);
            }

            $this->logServiceEvent('api_objects_retrieved', [
                'sync_id' => $syncLog->id,
                'object_count' => $apiObjects->count(),
                'duration_seconds' => round($apiDuration, 3),
                'throughput_objects_per_second' => $apiObjects->count() > 0 ? round($apiObjects->count() / $apiDuration, 2) : 0,
            ]);

            // Process objects in batches
            $stats = [
                'objects_processed' => 0,
                'objects_created' => 0,
                'objects_updated' => 0,
                'objects_deleted' => 0,
            ];

            $batches = $apiObjects->chunk($this->config['batch_size']);
            $batchCount = $batches->count();
            $processStartTime = microtime(true);
            
            $this->logServiceEvent('batch_processing_started', [
                'sync_id' => $syncLog->id,
                'total_batches' => $batchCount,
                'batch_size' => $this->config['batch_size'],
            ]);
            
            foreach ($batches as $batchIndex => $batch) {
                $batchStartTime = microtime(true);
                $batchStartMemory = memory_get_usage(true);
                
                $batchStats = $this->processBatch($batch, $batchIndex + 1, $batchCount);
                $stats['objects_processed'] += $batchStats['processed'];
                $stats['objects_created'] += $batchStats['created'];
                $stats['objects_updated'] += $batchStats['updated'];
                
                $batchDuration = microtime(true) - $batchStartTime;
                $batchMemoryUsed = memory_get_usage(true) - $batchStartMemory;
                
                $this->logServiceEvent('batch_processed', [
                    'sync_id' => $syncLog->id,
                    'batch_number' => $batchIndex + 1,
                    'batch_size' => $batch->count(),
                    'processed' => $batchStats['processed'],
                    'created' => $batchStats['created'],
                    'updated' => $batchStats['updated'],
                    'duration_seconds' => round($batchDuration, 3),
                    'memory_used_bytes' => $batchMemoryUsed,
                    'throughput_objects_per_second' => $batch->count() > 0 ? round($batch->count() / $batchDuration, 2) : 0,
                ]);
                
                // Update progress
                $syncLog->updateStats($stats);
            }
            
            $processDuration = microtime(true) - $processStartTime;
            
            $this->logServiceEvent('batch_processing_completed', [
                'sync_id' => $syncLog->id,
                'total_batches' => $batchCount,
                'total_duration_seconds' => round($processDuration, 3),
                'average_batch_duration' => $batchCount > 0 ? round($processDuration / $batchCount, 3) : 0,
                'total_processed' => $stats['objects_processed'],
            ]);

            // Cleanup stale objects if configured
            if ($this->config['cleanup_stale']) {
                $cleanupStartTime = microtime(true);
                $cutoffDate = Carbon::now()->subDays($this->config['cleanup_after_days']);
                $stats['objects_deleted'] = $this->repository->deleteStale($cutoffDate);
                $cleanupDuration = microtime(true) - $cleanupStartTime;
                
                $this->logServiceEvent('cleanup_completed', [
                    'sync_id' => $syncLog->id,
                    'cutoff_date' => $cutoffDate->toDateTimeString(),
                    'objects_deleted' => $stats['objects_deleted'],
                    'duration_seconds' => round($cleanupDuration, 3),
                ]);
            }

            // Mark as completed
            $syncLog->markCompleted($stats);
            
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            $this->logServiceEvent('sync_all_completed', [
                'sync_id' => $syncLog->id,
                'total_duration_seconds' => round($totalDuration, 3),
                'objects_processed' => $stats['objects_processed'],
                'objects_created' => $stats['objects_created'],
                'objects_updated' => $stats['objects_updated'],
                'objects_deleted' => $stats['objects_deleted'],
                'throughput_objects_per_second' => $stats['objects_processed'] > 0 ? round($stats['objects_processed'] / $totalDuration, 2) : 0,
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_peak' => $peakMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
            ]);
            
            // Emit sync completed event
            Event::dispatch(new SyncCompleted($syncLog, $stats));

            return $syncLog;

        } catch (\Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->logServiceEvent('sync_all_failed', [
                'sync_id' => $syncLog->id,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
                'partial_stats' => $stats ?? [],
            ], 'error');

            $syncLog->markFailed($e->getMessage());
            
            // Emit sync failed event
            Event::dispatch(new SyncFailed($syncLog, $e, ['type' => 'full_sync']));
            
            throw $e;
        }
    }

    /**
     * Synchronize a specific firewall object by name.
     * 
     * @param string $objectName The name of the object to synchronize
     * @return SyncLog The sync operation log entry
     */
    public function syncSpecific(string $objectName): SyncLog
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $syncLog = SyncLog::createSyncEntry(SyncLog::OPERATION_OBJECT_SYNC);
        
        $this->logServiceEvent('sync_specific_started', [
            'sync_id' => $syncLog->id,
            'object_name' => $objectName,
            'memory_usage_start' => $startMemory,
        ]);
        
        try {
            // Emit sync started event
            Event::dispatch(new SyncStarted($syncLog, [
                'type' => 'object_sync',
                'object_name' => $objectName
            ]));

            // Ensure authentication
            $authStartTime = microtime(true);
            if (!$this->ensureAuthenticated()) {
                throw new \Exception('Failed to authenticate with Hillstone API');
            }
            $authDuration = microtime(true) - $authStartTime;
            
            $this->logServiceEvent('authentication_completed', [
                'sync_id' => $syncLog->id,
                'object_name' => $objectName,
                'duration_seconds' => round($authDuration, 3),
            ]);

            // Get specific object from API
            $apiStartTime = microtime(true);
            $apiObjectData = $this->client->getSpecificAddressBookObject($objectName);
            $apiDuration = microtime(true) - $apiStartTime;
            
            if (empty($apiObjectData)) {
                $this->logServiceEvent('object_not_found_in_api', [
                    'sync_id' => $syncLog->id,
                    'object_name' => $objectName,
                    'api_duration_seconds' => round($apiDuration, 3),
                ], 'warning');
                
                throw new \Exception("Object '{$objectName}' not found in API");
            }
            
            $this->logServiceEvent('api_object_retrieved', [
                'sync_id' => $syncLog->id,
                'object_name' => $objectName,
                'duration_seconds' => round($apiDuration, 3),
                'response_size_bytes' => strlen(json_encode($apiObjectData)),
            ]);

            // Process the single object
            $processStartTime = microtime(true);
            $result = $this->processObject($apiObjectData);
            $processDuration = microtime(true) - $processStartTime;
            
            $stats = [
                'objects_processed' => 1,
                'objects_created' => $result['action'] === 'created' ? 1 : 0,
                'objects_updated' => $result['action'] === 'updated' ? 1 : 0,
                'objects_deleted' => 0,
            ];

            // Mark as completed
            $syncLog->markCompleted($stats);
            
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            $this->logServiceEvent('sync_specific_completed', [
                'sync_id' => $syncLog->id,
                'object_name' => $objectName,
                'action' => $result['action'],
                'total_duration_seconds' => round($totalDuration, 3),
                'api_duration_seconds' => round($apiDuration, 3),
                'process_duration_seconds' => round($processDuration, 3),
                'auth_duration_seconds' => round($authDuration, 3),
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_peak' => $peakMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
            ]);
            
            // Emit sync completed event
            Event::dispatch(new SyncCompleted($syncLog, array_merge($stats, $result)));

            return $syncLog;

        } catch (\Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->logServiceEvent('sync_specific_failed', [
                'sync_id' => $syncLog->id,
                'object_name' => $objectName,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
            ], 'error');

            $syncLog->markFailed($e->getMessage());
            
            // Emit sync failed event
            Event::dispatch(new SyncFailed($syncLog, $e, [
                'type' => 'object_sync',
                'object_name' => $objectName
            ]));
            
            throw $e;
        }
    }

    /**
     * Get the status of the last synchronization operation.
     * 
     * @return SyncLog|null The last sync log entry or null if no sync has been performed
     */
    public function getLastSyncStatus(): ?SyncLog
    {
        return SyncLog::orderBy('started_at', 'desc')->first();
    }

    /**
     * Get synchronization statistics.
     * 
     * @param int $days Number of days to look back
     * @return array Statistics array
     */
    public function getSyncStatistics(int $days = 7): array
    {
        $since = Carbon::now()->subDays($days);
        
        $logs = SyncLog::where('started_at', '>=', $since)
            ->orderBy('started_at', 'desc')
            ->get();

        return [
            'total_syncs' => $logs->count(),
            'successful_syncs' => $logs->where('status', SyncLog::STATUS_COMPLETED)->count(),
            'failed_syncs' => $logs->where('status', SyncLog::STATUS_FAILED)->count(),
            'running_syncs' => $logs->where('status', SyncLog::STATUS_STARTED)->count(),
            'total_objects_processed' => $logs->sum('objects_processed'),
            'total_objects_created' => $logs->sum('objects_created'),
            'total_objects_updated' => $logs->sum('objects_updated'),
            'total_objects_deleted' => $logs->sum('objects_deleted'),
            'last_sync' => $logs->first(),
            'average_duration' => $logs->where('status', SyncLog::STATUS_COMPLETED)
                ->avg(function ($log) {
                    return $log->duration;
                }),
        ];
    }

    /**
     * Check if a sync operation is currently running.
     * 
     * @return bool True if sync is running, false otherwise
     */
    public function isSyncRunning(): bool
    {
        return SyncLog::where('status', SyncLog::STATUS_STARTED)->exists();
    }

    /**
     * Ensure the client is authenticated.
     * 
     * @return bool True if authenticated, false otherwise
     */
    private function ensureAuthenticated(): bool
    {
        if (!$this->client->isAuthenticated()) {
            return $this->client->authenticate();
        }
        
        return true;
    }

    /**
     * Process a batch of objects.
     * 
     * @param Collection $batch
     * @param int $batchNumber
     * @param int $totalBatches
     * @return array Batch processing statistics
     */
    private function processBatch(Collection $batch, int $batchNumber = 1, int $totalBatches = 1): array
    {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
        ];

        foreach ($batch as $objectIndex => $objectData) {
            $objectStartTime = microtime(true);
            try {
                $result = $this->processObject($objectData);
                $stats['processed']++;
                
                if ($result['action'] === 'created') {
                    $stats['created']++;
                } elseif ($result['action'] === 'updated') {
                    $stats['updated']++;
                }
                
                $objectDuration = microtime(true) - $objectStartTime;
                
                // Log individual object processing if verbose logging is enabled
                if ($this->config['logging']['log_performance_metrics'] ?? false) {
                    $this->logServiceEvent('object_processed', [
                        'batch_number' => $batchNumber,
                        'object_index' => $objectIndex + 1,
                        'object_name' => $objectData['name'] ?? 'unknown',
                        'action' => $result['action'],
                        'duration_seconds' => round($objectDuration, 4),
                    ], 'debug');
                }
                
            } catch (\Exception $e) {
                $objectDuration = microtime(true) - $objectStartTime;
                
                $this->logServiceEvent('object_processing_failed', [
                    'batch_number' => $batchNumber,
                    'object_index' => $objectIndex + 1,
                    'object_name' => $objectData['name'] ?? 'unknown',
                    'error_message' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'duration_before_failure' => round($objectDuration, 4),
                    'stack_trace' => $e->getTraceAsString(),
                ], 'error');
            }
        }

        return $stats;
    }

    /**
     * Process a single object.
     * 
     * @param array $objectData
     * @return array Processing result with action and object
     */
    private function processObject(array $objectData): array
    {
        $objectName = $objectData['name'] ?? '';
        $objectStartTime = microtime(true);
        
        // Check if object exists
        $existingObject = $this->repository->findByName($objectName);
        $action = $existingObject ? 'updated' : 'created';
        
        // Apply conflict resolution strategy
        if ($existingObject && $this->config['conflict_resolution'] === 'skip_existing') {
            $this->logServiceEvent('object_skipped', [
                'object_name' => $objectName,
                'reason' => 'conflict_resolution_skip_existing',
                'existing_last_synced' => $existingObject->last_synced_at?->toISOString(),
            ], 'info');
            
            return [
                'action' => 'skipped',
                'object' => $existingObject
            ];
        }

        // Create or update the object
        $repositoryStartTime = microtime(true);
        $object = $this->repository->createOrUpdate($objectData);
        $repositoryDuration = microtime(true) - $repositoryStartTime;
        
        // Emit object synced event
        Event::dispatch(new ObjectSynced($object, $action, [
            'previous_data' => $existingObject ? $existingObject->toArray() : null,
            'new_data' => $object->toArray()
        ]));

        $totalDuration = microtime(true) - $objectStartTime;
        
        $this->logServiceEvent('object_processed_successfully', [
            'object_name' => $objectName,
            'action' => $action,
            'total_duration_seconds' => round($totalDuration, 4),
            'repository_duration_seconds' => round($repositoryDuration, 4),
            'has_previous_data' => $existingObject !== null,
            'object_id' => $object->id ?? null,
        ], 'debug');

        return [
            'action' => $action,
            'object' => $object
        ];
    }

    /**
     * Log service events with structured context.
     * 
     * @param string $event
     * @param array $context
     * @param string $level
     */
    private function logServiceEvent(string $event, array $context = [], string $level = 'info'): void
    {
        if (!($this->config['logging']['log_sync_operations'] ?? true)) {
            return;
        }

        $logContext = array_merge([
            'service' => 'SyncService',
            'event' => $event,
            'timestamp' => now()->toISOString(),
        ], $context);

        // Add performance metrics if enabled
        if ($this->config['logging']['log_performance_metrics'] ?? false) {
            $logContext['memory_usage'] = memory_get_usage(true);
            $logContext['peak_memory'] = memory_get_peak_usage(true);
        }

        switch ($level) {
            case 'debug':
                Log::channel($this->logChannel)->debug("SyncService: {$event}", $logContext);
                break;
            case 'info':
                Log::channel($this->logChannel)->info("SyncService: {$event}", $logContext);
                break;
            case 'warning':
                Log::channel($this->logChannel)->warning("SyncService: {$event}", $logContext);
                break;
            case 'error':
                Log::channel($this->logChannel)->error("SyncService: {$event}", $logContext);
                break;
            default:
                Log::channel($this->logChannel)->info("SyncService: {$event}", $logContext);
        }
    }
}