<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectData;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectDataIP;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ObjectRepository implements ObjectRepositoryInterface
{
    protected array $config;
    protected string $logChannel;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'logging' => [
                'channel' => 'default',
                'log_sync_operations' => true,
                'log_performance_metrics' => false,
            ],
        ], $config);
        
        $this->logChannel = $this->config['logging']['channel'] ?? 'default';
        
        $this->logRepositoryEvent('repository_initialized', [
            'config' => array_keys($this->config),
        ]);
    }

    /**
     * Create a new firewall object or update an existing one.
     * 
     * @param array $objectData The object data to create or update
     * @return HillstoneObject The created or updated object
     */
    public function createOrUpdate(array $objectData): HillstoneObject
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $objectName = $objectData['name'] ?? 'unknown';
        
        $this->logRepositoryEvent('create_or_update_started', [
            'object_name' => $objectName,
            'data_size_bytes' => strlen(json_encode($objectData)),
            'memory_usage_start' => $startMemory,
        ]);

        try {
            // Validate required fields
            $validationStartTime = microtime(true);
            $this->validateObjectData($objectData);
            $validationDuration = microtime(true) - $validationStartTime;
            
            $this->logRepositoryEvent('validation_completed', [
                'object_name' => $objectName,
                'duration_seconds' => round($validationDuration, 4),
            ], 'debug');

            return DB::transaction(function () use ($objectData, $startTime, $objectName) {
                $transactionStartTime = microtime(true);
                
                // Upsert the main object
                $upsertStartTime = microtime(true);
                $object = $this->upsertObject($objectData);
                $upsertDuration = microtime(true) - $upsertStartTime;
                
                $this->logRepositoryEvent('main_object_upserted', [
                    'object_name' => $objectName,
                    'object_id' => $object->id,
                    'duration_seconds' => round($upsertDuration, 4),
                ], 'debug');
                
                // Upsert the object data if present
                if (isset($objectData['object_data'])) {
                    $objectDataStartTime = microtime(true);
                    $this->upsertObjectData($objectData['object_data'], $object->name);
                    $objectDataDuration = microtime(true) - $objectDataStartTime;
                    
                    $this->logRepositoryEvent('object_data_upserted', [
                        'object_name' => $objectName,
                        'duration_seconds' => round($objectDataDuration, 4),
                        'ip_count' => is_array($objectData['object_data']['ip'] ?? null) ? count($objectData['object_data']['ip']) : 0,
                    ], 'debug');
                }

                $transactionDuration = microtime(true) - $transactionStartTime;
                $totalDuration = microtime(true) - $startTime;
                $endMemory = memory_get_usage(true);
                
                $this->logRepositoryEvent('create_or_update_completed', [
                    'object_name' => $objectName,
                    'object_id' => $object->id,
                    'total_duration_seconds' => round($totalDuration, 4),
                    'transaction_duration_seconds' => round($transactionDuration, 4),
                    'memory_usage_delta' => $endMemory - ($startMemory ?? 0),
                    'operation' => 'upsert',
                ]);

                return $object->fresh();
            });
            
        } catch (\Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->logRepositoryEvent('create_or_update_failed', [
                'object_name' => $objectName,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 4),
                'memory_usage_at_failure' => $endMemory,
            ], 'error');
            
            throw $e;
        }
    }

    /**
     * Find a firewall object by its name.
     * 
     * @param string $name The name of the object to find
     * @return HillstoneObject|null The found object or null if not found
     */
    public function findByName(string $name): ?HillstoneObject
    {
        $startTime = microtime(true);
        
        $this->logRepositoryEvent('find_by_name_started', [
            'object_name' => $name,
        ], 'debug');
        
        try {
            $queryStartTime = microtime(true);
            $object = HillstoneObject::with(['objectData.ipAddresses'])
                ->where('name', $name)
                ->first();
            $queryDuration = microtime(true) - $queryStartTime;
            
            $totalDuration = microtime(true) - $startTime;
            
            $this->logRepositoryEvent('find_by_name_completed', [
                'object_name' => $name,
                'found' => $object !== null,
                'object_id' => $object?->id,
                'query_duration_seconds' => round($queryDuration, 4),
                'total_duration_seconds' => round($totalDuration, 4),
                'has_object_data' => $object?->objectData !== null,
                'ip_count' => $object?->objectData?->ipAddresses?->count() ?? 0,
            ], 'debug');
            
            return $object;
            
        } catch (\Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            
            $this->logRepositoryEvent('find_by_name_failed', [
                'object_name' => $name,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 4),
            ], 'error');
            
            return null;
        }
    }

    /**
     * Delete stale objects that haven't been synced since the cutoff date.
     * 
     * @param Carbon $cutoffDate Objects not synced since this date will be deleted
     * @return int The number of objects deleted
     */
    public function deleteStale($cutoffDate): int
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        if (!$cutoffDate instanceof Carbon) {
            $cutoffDate = Carbon::parse($cutoffDate);
        }

        $this->logRepositoryEvent('delete_stale_started', [
            'cutoff_date' => $cutoffDate->toDateTimeString(),
            'memory_usage_start' => $startMemory,
        ]);

        return DB::transaction(function () use ($cutoffDate, $startTime, $startMemory) {
            $transactionStartTime = microtime(true);
            
            // Find stale objects
            $queryStartTime = microtime(true);
            $staleObjects = HillstoneObject::where('last_synced_at', '<', $cutoffDate)
                ->orWhereNull('last_synced_at')
                ->get();
            $queryDuration = microtime(true) - $queryStartTime;
            
            $this->logRepositoryEvent('stale_objects_identified', [
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'stale_count' => $staleObjects->count(),
                'query_duration_seconds' => round($queryDuration, 4),
            ]);

            $deletedCount = 0;
            $failedCount = 0;

            foreach ($staleObjects as $index => $object) {
                $deleteStartTime = microtime(true);
                try {
                    // Delete related object data and IPs (cascade should handle this)
                    $object->delete();
                    $deletedCount++;
                    $deleteDuration = microtime(true) - $deleteStartTime;
                    
                    $this->logRepositoryEvent('stale_object_deleted', [
                        'object_name' => $object->name,
                        'object_id' => $object->id,
                        'last_synced_at' => $object->last_synced_at?->toISOString(),
                        'delete_duration_seconds' => round($deleteDuration, 4),
                        'progress' => ($index + 1) . '/' . $staleObjects->count(),
                    ], 'debug');
                    
                } catch (\Exception $e) {
                    $failedCount++;
                    $deleteDuration = microtime(true) - $deleteStartTime;
                    
                    $this->logRepositoryEvent('stale_object_delete_failed', [
                        'object_name' => $object->name,
                        'object_id' => $object->id,
                        'error_message' => $e->getMessage(),
                        'error_class' => get_class($e),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                        'duration_before_failure' => round($deleteDuration, 4),
                        'progress' => ($index + 1) . '/' . $staleObjects->count(),
                    ], 'error');
                }
            }

            $transactionDuration = microtime(true) - $transactionStartTime;
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);

            $this->logRepositoryEvent('delete_stale_completed', [
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'total_found' => $staleObjects->count(),
                'deleted_count' => $deletedCount,
                'failed_count' => $failedCount,
                'success_rate' => $staleObjects->count() > 0 ? round(($deletedCount / $staleObjects->count()) * 100, 2) : 100,
                'total_duration_seconds' => round($totalDuration, 3),
                'transaction_duration_seconds' => round($transactionDuration, 3),
                'query_duration_seconds' => round($queryDuration, 4),
                'average_delete_time' => $deletedCount > 0 ? round($transactionDuration / $deletedCount, 4) : 0,
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
            ]);

            return $deletedCount;
        });
    }

    /**
     * Bulk create or update multiple objects for performance optimization.
     * 
     * @param array $objectsData Array of object data arrays
     * @return Collection Collection of created/updated objects
     */
    public function bulkCreateOrUpdate(array $objectsData): Collection
    {
        $results = collect();

        return DB::transaction(function () use ($objectsData, $results) {
            // Process in chunks to manage memory
            $chunks = array_chunk($objectsData, 50);
            
            foreach ($chunks as $chunk) {
                foreach ($chunk as $objectData) {
                    try {
                        $object = $this->createOrUpdate($objectData);
                        $results->push($object);
                    } catch (\Exception $e) {
                        Log::error('Error in bulk operation', [
                            'object_name' => $objectData['name'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            Log::info('Bulk operation completed', [
                'total_processed' => count($objectsData),
                'successful' => $results->count()
            ]);

            return $results;
        });
    }

    /**
     * Get objects that need synchronization (haven't been synced recently).
     * 
     * @param Carbon $since Only return objects not synced since this date
     * @return Collection Collection of objects needing sync
     */
    public function getObjectsNeedingSync(Carbon $since): Collection
    {
        return HillstoneObject::where('last_synced_at', '<', $since)
            ->orWhereNull('last_synced_at')
            ->with(['objectData.ipAddresses'])
            ->get();
    }

    /**
     * Update the last synced timestamp for an object.
     * 
     * @param string $name Object name
     * @param Carbon|null $timestamp Sync timestamp (defaults to now)
     * @return bool Success status
     */
    public function updateLastSynced(string $name, ?Carbon $timestamp = null): bool
    {
        $timestamp = $timestamp ?? Carbon::now();
        
        try {
            $updated = HillstoneObject::where('name', $name)
                ->update(['last_synced_at' => $timestamp]);

            // Also update object data if it exists
            HillstoneObjectData::where('name', $name)
                ->update(['last_synced_at' => $timestamp]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('Error updating last synced timestamp', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate object data structure.
     * 
     * @param array $objectData
     * @throws \InvalidArgumentException
     */
    private function validateObjectData(array $objectData): void
    {
        if (empty($objectData['name'])) {
            throw new \InvalidArgumentException('Object name is required');
        }

        // Sanitize name
        $objectData['name'] = $this->sanitizeString($objectData['name']);

        if (strlen($objectData['name']) > 255) {
            throw new \InvalidArgumentException('Object name cannot exceed 255 characters');
        }
    }

    /**
     * Upsert the main HillstoneObject.
     * 
     * @param array $objectData
     * @return HillstoneObject
     */
    private function upsertObject(array $objectData): HillstoneObject
    {
        $sanitizedData = [
            'name' => $this->sanitizeString($objectData['name']),
            'member' => $objectData['member'] ?? [],
            'is_ipv6' => (bool) ($objectData['is_ipv6'] ?? false),
            'predefined' => (bool) ($objectData['predefined'] ?? false),
            'last_synced_at' => Carbon::now(),
        ];

        return HillstoneObject::updateOrCreate(
            ['name' => $sanitizedData['name']],
            $sanitizedData
        );
    }

    /**
     * Upsert object data and related IP addresses.
     * 
     * @param array $objectDataArray
     * @param string $objectName
     */
    private function upsertObjectData(array $objectDataArray, string $objectName): void
    {
        $sanitizedData = [
            'name' => $objectName,
            'ip' => $objectDataArray['ip'] ?? [],
            'is_ipv6' => (bool) ($objectDataArray['is_ipv6'] ?? false),
            'predefined' => (bool) ($objectDataArray['predefined'] ?? false),
            'last_synced_at' => Carbon::now(),
        ];

        $objectData = HillstoneObjectData::updateOrCreate(
            ['name' => $objectName],
            $sanitizedData
        );

        // Handle IP addresses
        if (isset($objectDataArray['ip']) && is_array($objectDataArray['ip'])) {
            $this->upsertIpAddresses($objectData, $objectDataArray['ip']);
        }
    }

    /**
     * Upsert IP addresses for object data.
     * 
     * @param HillstoneObjectData $objectData
     * @param array $ipData
     */
    private function upsertIpAddresses(HillstoneObjectData $objectData, array $ipData): void
    {
        // Remove existing IP addresses for this object data
        HillstoneObjectDataIP::where('hillstone_object_data_id', $objectData->id)->delete();

        // Insert new IP addresses
        foreach ($ipData as $ip) {
            if (is_array($ip)) {
                HillstoneObjectDataIP::create([
                    'hillstone_object_data_id' => $objectData->id,
                    'ip_addr' => $this->sanitizeString($ip['ip_addr'] ?? ''),
                    'ip_address' => $this->sanitizeIpAddress($ip['ip_address'] ?? ''),
                    'netmask' => $this->sanitizeIpAddress($ip['netmask'] ?? ''),
                    'flag' => (int) ($ip['flag'] ?? 0),
                ]);
            }
        }
    }

    /**
     * Sanitize string input.
     * 
     * @param string $input
     * @return string
     */
    private function sanitizeString(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * Sanitize and validate IP address.
     * 
     * @param string $ip
     * @return string
     */
    private function sanitizeIpAddress(string $ip): string
    {
        $sanitized = trim($ip);
        
        // Basic IP validation - allow both IPv4 and IPv6
        if (filter_var($sanitized, FILTER_VALIDATE_IP)) {
            return $sanitized;
        }
        
        // If not a valid IP, return as-is but log warning
        $this->logRepositoryEvent('invalid_ip_address_format', [
            'original_ip' => $ip,
            'sanitized_ip' => $sanitized,
        ], 'warning');
        
        return $sanitized;
    }

    /**
     * Log repository events with structured context.
     * 
     * @param string $event
     * @param array $context
     * @param string $level
     */
    private function logRepositoryEvent(string $event, array $context = [], string $level = 'info'): void
    {
        if (!($this->config['logging']['log_sync_operations'] ?? true)) {
            return;
        }

        $logContext = array_merge([
            'service' => 'ObjectRepository',
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
                Log::channel($this->logChannel)->debug("ObjectRepository: {$event}", $logContext);
                break;
            case 'info':
                Log::channel($this->logChannel)->info("ObjectRepository: {$event}", $logContext);
                break;
            case 'warning':
                Log::channel($this->logChannel)->warning("ObjectRepository: {$event}", $logContext);
                break;
            case 'error':
                Log::channel($this->logChannel)->error("ObjectRepository: {$event}", $logContext);
                break;
            default:
                Log::channel($this->logChannel)->info("ObjectRepository: {$event}", $logContext);
        }
    }
}