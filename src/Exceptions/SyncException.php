<?php

namespace MrWolfGb\HillstoneFirewallSync\Exceptions;

use Throwable;

/**
 * Exception thrown when synchronization operations fail.
 * 
 * This exception is thrown when:
 * - Database synchronization operations fail
 * - Data validation errors occur during sync
 * - Conflict resolution fails
 * - Sync jobs encounter unrecoverable errors
 * - Data integrity issues are detected
 */
class SyncException extends HillstoneException
{
    /**
     * The sync operation type that failed.
     *
     * @var string|null
     */
    protected ?string $operationType = null;

    /**
     * The number of objects processed before failure.
     *
     * @var int
     */
    protected int $objectsProcessed = 0;

    /**
     * Create a new sync exception.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param Throwable|null $previous The previous exception
     * @param array $context Additional context data
     * @param string|null $operationType The sync operation type
     * @param int $objectsProcessed Number of objects processed
     */
    public function __construct(
        string $message = 'Synchronization operation failed',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = [],
        ?string $operationType = null,
        int $objectsProcessed = 0
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->operationType = $operationType;
        $this->objectsProcessed = $objectsProcessed;
    }

    /**
     * Get the sync operation type.
     *
     * @return string|null
     */
    public function getOperationType(): ?string
    {
        return $this->operationType;
    }

    /**
     * Get the number of objects processed before failure.
     *
     * @return int
     */
    public function getObjectsProcessed(): int
    {
        return $this->objectsProcessed;
    }

    /**
     * Create an exception for database synchronization failures.
     *
     * @param string $operation
     * @param Throwable|null $previous
     * @param array $context
     * @return static
     */
    public static function databaseError(string $operation, ?Throwable $previous = null, array $context = []): static
    {
        return new static(
            "Database error during {$operation} operation.",
            500,
            $previous,
            array_merge(['operation' => $operation], $context),
            $operation
        );
    }

    /**
     * Create an exception for data validation failures.
     *
     * @param string $objectName
     * @param array $validationErrors
     * @param array $context
     * @return static
     */
    public static function validationError(string $objectName, array $validationErrors, array $context = []): static
    {
        $errorMessages = implode(', ', $validationErrors);
        
        return new static(
            "Validation failed for object '{$objectName}': {$errorMessages}",
            422,
            null,
            array_merge([
                'object_name' => $objectName,
                'validation_errors' => $validationErrors
            ], $context),
            'validation'
        );
    }

    /**
     * Create an exception for conflict resolution failures.
     *
     * @param string $objectName
     * @param string $conflictType
     * @param array $context
     * @return static
     */
    public static function conflictResolutionFailed(
        string $objectName,
        string $conflictType,
        array $context = []
    ): static {
        return new static(
            "Failed to resolve {$conflictType} conflict for object '{$objectName}'.",
            409,
            null,
            array_merge([
                'object_name' => $objectName,
                'conflict_type' => $conflictType
            ], $context),
            'conflict_resolution'
        );
    }

    /**
     * Create an exception for sync job failures.
     *
     * @param string $jobClass
     * @param int $objectsProcessed
     * @param Throwable|null $previous
     * @param array $context
     * @return static
     */
    public static function jobFailed(
        string $jobClass,
        int $objectsProcessed = 0,
        ?Throwable $previous = null,
        array $context = []
    ): static {
        return new static(
            "Sync job '{$jobClass}' failed after processing {$objectsProcessed} objects.",
            500,
            $previous,
            array_merge(['job_class' => $jobClass], $context),
            'job_execution',
            $objectsProcessed
        );
    }

    /**
     * Create an exception for data integrity issues.
     *
     * @param string $issue
     * @param array $affectedObjects
     * @param array $context
     * @return static
     */
    public static function dataIntegrityError(
        string $issue,
        array $affectedObjects = [],
        array $context = []
    ): static {
        $objectCount = count($affectedObjects);
        $message = "Data integrity issue detected: {$issue}";
        
        if ($objectCount > 0) {
            $message .= " (affects {$objectCount} objects)";
        }

        return new static(
            $message,
            500,
            null,
            array_merge([
                'issue' => $issue,
                'affected_objects' => $affectedObjects,
                'affected_count' => $objectCount
            ], $context),
            'data_integrity'
        );
    }

    /**
     * Create an exception for partial sync failures.
     *
     * @param int $totalObjects
     * @param int $processedObjects
     * @param int $failedObjects
     * @param array $errors
     * @param array $context
     * @return static
     */
    public static function partialFailure(
        int $totalObjects,
        int $processedObjects,
        int $failedObjects,
        array $errors = [],
        array $context = []
    ): static {
        return new static(
            "Partial sync failure: {$processedObjects}/{$totalObjects} objects processed successfully, {$failedObjects} failed.",
            206,
            null,
            array_merge([
                'total_objects' => $totalObjects,
                'processed_objects' => $processedObjects,
                'failed_objects' => $failedObjects,
                'errors' => $errors
            ], $context),
            'partial_sync',
            $processedObjects
        );
    }

    /**
     * Convert the exception to an array including sync-specific data.
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        
        if ($this->operationType !== null) {
            $data['operation_type'] = $this->operationType;
        }
        
        $data['objects_processed'] = $this->objectsProcessed;

        return $data;
    }
}