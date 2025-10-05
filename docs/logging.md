# Hillstone Firewall Sync - Comprehensive Logging Guide

## Overview

The Hillstone Firewall Sync package provides extensive logging capabilities to help with monitoring, debugging, and performance analysis. This document outlines all logging features and configuration options.

## Logging Categories

### 1. Authentication Events
- **Purpose**: Track authentication attempts, successes, failures, and token management
- **Configuration**: `log_authentication`
- **Events Logged**:
  - Authentication attempts and results
  - Token cache hits/misses
  - Token expiration and refresh
  - Authentication validation failures

### 2. Sync Operations
- **Purpose**: Monitor synchronization processes and their outcomes
- **Configuration**: `log_sync_operations`
- **Events Logged**:
  - Sync start/completion/failure
  - Batch processing progress
  - Individual object processing
  - Cleanup operations

### 3. Performance Metrics
- **Purpose**: Track execution times, memory usage, and throughput
- **Configuration**: `log_performance_metrics`
- **Metrics Logged**:
  - Operation duration
  - Memory usage (start, end, peak, delta)
  - Throughput (objects/second, bytes/second)
  - Performance categorization (fast, moderate, slow, very_slow)

### 4. Error Logging
- **Purpose**: Capture detailed error information for troubleshooting
- **Configuration**: `log_stack_traces`, `log_error_context`
- **Information Logged**:
  - Error messages and exception classes
  - File and line number where error occurred
  - Stack traces (configurable)
  - Error context and previous exceptions
  - Retry attempt information

### 5. API Communication
- **Purpose**: Monitor HTTP requests and responses to Hillstone API
- **Configuration**: `log_requests`, `log_responses`
- **Information Logged**:
  - Request/response headers and bodies
  - HTTP status codes
  - Response times
  - Rate limiting information

## Configuration Options

### Basic Logging Settings

```php
'logging' => [
    'channel' => 'hillstone',           // Laravel log channel to use
    'level' => 'info',                  // Minimum log level
    'log_sync_operations' => true,      // Enable sync operation logging
    'log_authentication' => true,       // Enable authentication logging
    'log_performance_metrics' => false, // Enable performance metrics
],
```

### Advanced Logging Settings

```php
'logging' => [
    // Request/Response Logging
    'log_requests' => false,
    'log_responses' => false,
    'log_request_headers' => false,
    'log_response_headers' => false,
    
    // Performance Metrics
    'log_memory_usage' => false,
    'log_execution_time' => true,
    'log_throughput_metrics' => false,
    
    // Error Logging
    'log_stack_traces' => true,
    'log_error_context' => true,
    'log_retry_attempts' => true,
    
    // Structured Logging
    'include_context_data' => true,
    'include_system_info' => false,
    'log_correlation_id' => false,
    
    // Security and Performance
    'exclude_sensitive_data' => true,
    'max_log_payload_size' => 10240,
    'truncate_long_messages' => true,
],
```

## Log Structure

All log entries follow a consistent structure:

```json
{
    "service": "SyncService",
    "event": "sync_all_completed",
    "timestamp": "2024-01-15T10:30:45.123Z",
    "sync_id": 12345,
    "duration_seconds": 45.678,
    "objects_processed": 150,
    "objects_created": 25,
    "objects_updated": 125,
    "throughput_objects_per_second": 3.29,
    "memory_usage_start": 52428800,
    "memory_usage_end": 67108864,
    "memory_usage_delta": 14680064,
    "performance_category": "moderate"
}
```

## Event Types by Service

### SyncService Events
- `service_initialized` - Service instantiation
- `sync_all_started` - Full sync operation started
- `sync_all_completed` - Full sync operation completed
- `sync_all_failed` - Full sync operation failed
- `sync_specific_started` - Specific object sync started
- `sync_specific_completed` - Specific object sync completed
- `sync_specific_failed` - Specific object sync failed
- `authentication_completed` - Authentication step completed
- `api_objects_retrieved` - Objects retrieved from API
- `batch_processing_started` - Batch processing started
- `batch_processed` - Individual batch processed
- `batch_processing_completed` - All batches processed
- `cleanup_completed` - Stale object cleanup completed
- `object_processed_successfully` - Individual object processed
- `object_skipped` - Object skipped due to conflict resolution

### ObjectRepository Events
- `repository_initialized` - Repository instantiation
- `create_or_update_started` - Object upsert started
- `create_or_update_completed` - Object upsert completed
- `create_or_update_failed` - Object upsert failed
- `find_by_name_started` - Object lookup started
- `find_by_name_completed` - Object lookup completed
- `find_by_name_failed` - Object lookup failed
- `delete_stale_started` - Stale object deletion started
- `delete_stale_completed` - Stale object deletion completed
- `stale_objects_identified` - Stale objects found
- `stale_object_deleted` - Individual stale object deleted
- `stale_object_delete_failed` - Stale object deletion failed

### AuthenticationService Events
- `authentication_started` - Authentication process started
- `success` - Authentication successful
- `failed` - Authentication failed
- `cache_hit` - Valid token found in cache
- `cache_miss` - No valid token in cache
- `token_expired` - Token expired
- `validation_failed` - Token validation failed
- `cleared` - Authentication cache cleared
- `retry_delay_started` - Retry delay initiated
- `authentication_exhausted` - All retry attempts failed

### HillstoneClient Events
- `get_all_objects_started` - API request for all objects started
- `get_all_objects_completed` - API request for all objects completed
- `get_all_objects_failed` - API request for all objects failed
- `get_specific_object_started` - API request for specific object started
- `get_specific_object_completed` - API request for specific object completed
- `get_specific_object_failed` - API request for specific object failed
- `get_specific_object_not_found` - Specific object not found in API
- `connection_test_started` - Connection test started
- `connection_test_completed` - Connection test completed
- `connection_test_failed` - Connection test failed

### Job Events
- `job_started` - Background job started
- `job_completed_successfully` - Background job completed
- `job_failed` - Background job failed
- `job_skipped_concurrent_lock` - Job skipped due to concurrent execution
- `job_cancelled_sync_in_progress` - Job cancelled due to existing sync
- `job_lock_released` - Execution lock released

### Command Events
- `command_started` - Console command started
- `command_skipped_recent_sync` - Command skipped due to recent sync
- `command_failed` - Console command failed
- `direct_sync_started` - Direct sync execution started
- `direct_sync_completed` - Direct sync execution completed
- `direct_sync_failed` - Direct sync execution failed
- `queued_sync_dispatched` - Sync job dispatched to queue
- `queued_sync_dispatch_failed` - Sync job dispatch failed

## Performance Monitoring

### Key Performance Indicators

1. **Sync Duration**: Total time for sync operations
2. **Throughput**: Objects processed per second
3. **Memory Efficiency**: Memory usage patterns
4. **API Response Times**: Time taken for API calls
5. **Database Performance**: Time for database operations

### Performance Categories

- **Fast**: < 5 seconds
- **Moderate**: 5-10 seconds
- **Slow**: 10-30 seconds
- **Very Slow**: > 30 seconds

### Sample Performance Log

```json
{
    "service": "SyncService",
    "event": "performance_metrics",
    "operation": "sync_all",
    "duration_seconds": 12.456,
    "memory_usage_start": 52428800,
    "memory_usage_end": 67108864,
    "memory_usage_peak": 71303168,
    "memory_usage_delta": 14680064,
    "memory_efficiency": 28.0,
    "performance_category": "slow",
    "objects_processed": 200,
    "throughput_objects_per_second": 16.06
}
```

## Error Logging

### Error Context Structure

```json
{
    "service": "SyncService",
    "event": "error_occurred",
    "operation": "sync_all",
    "error_message": "Connection timeout",
    "error_class": "GuzzleHttp\\Exception\\ConnectException",
    "error_file": "/path/to/file.php",
    "error_line": 123,
    "error_code": 0,
    "stack_trace": "...",
    "error_context": {
        "previous_exception": null,
        "trace_count": 15
    },
    "duration_before_failure": 5.678,
    "memory_usage_at_failure": 58720256
}
```

## Security Considerations

### Sensitive Data Filtering

The logging system automatically filters sensitive information:

- Passwords and tokens
- Authentication cookies
- API keys and secrets
- Authorization headers

### Data Truncation

Large payloads are automatically truncated to prevent log bloat:

- Stack traces limited to 1000 characters
- Response bodies truncated if too large
- Truncation notices added to logs

## Best Practices

### 1. Log Level Configuration

- **Production**: Use `info` level, disable performance metrics
- **Staging**: Use `debug` level, enable performance metrics
- **Development**: Use `debug` level, enable all logging options

### 2. Log Channel Configuration

Create a dedicated log channel for Hillstone operations:

```php
// config/logging.php
'channels' => [
    'hillstone' => [
        'driver' => 'daily',
        'path' => storage_path('logs/hillstone.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

### 3. Monitoring and Alerting

Set up monitoring for critical events:

- Authentication failures
- Sync operation failures
- Performance degradation
- High error rates

### 4. Log Retention

Configure appropriate log retention policies:

- Keep detailed logs for 7-14 days
- Archive summary logs for longer periods
- Implement log rotation to manage disk space

## Troubleshooting with Logs

### Common Issues and Log Patterns

1. **Authentication Problems**
   - Look for `authentication_failed` events
   - Check `cache_miss` and `token_expired` events
   - Verify configuration in authentication logs

2. **Sync Performance Issues**
   - Monitor `performance_metrics` events
   - Check `throughput_objects_per_second` values
   - Look for `slow` or `very_slow` performance categories

3. **API Communication Problems**
   - Check `get_all_objects_failed` events
   - Look for timeout or connection errors
   - Monitor response times and status codes

4. **Database Issues**
   - Look for `create_or_update_failed` events
   - Check transaction rollback logs
   - Monitor database operation durations

### Log Analysis Queries

Use log analysis tools to query logs effectively:

```bash
# Find all failed sync operations
grep "sync_all_failed" hillstone.log

# Monitor performance trends
grep "performance_metrics" hillstone.log | jq '.duration_seconds'

# Check authentication issues
grep "authentication_failed" hillstone.log | jq '.error_message'

# Analyze throughput
grep "throughput_objects_per_second" hillstone.log | jq '.throughput_objects_per_second'
```

## Integration with Monitoring Tools

### ELK Stack Integration

Configure structured logging for Elasticsearch:

```php
'logging' => [
    'channel' => 'elasticsearch',
    'log_correlation_id' => true,
    'include_system_info' => true,
],
```

### Metrics Collection

Export key metrics to monitoring systems:

- Sync operation counts and durations
- Error rates and types
- Performance metrics
- Authentication success rates

This comprehensive logging system provides full visibility into the Hillstone Firewall Sync package operations, enabling effective monitoring, debugging, and performance optimization.