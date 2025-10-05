<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hillstone Firewall Connection Configuration
    |--------------------------------------------------------------------------
    |
    | This section contains the connection settings for your Hillstone NG
    | firewall system. These settings are used to establish API connections
    | and authenticate with the firewall management interface.
    |
    */
    'connection' => [
        'domain' => env('HILLSTONE_DOMAIN'),
        'base_url' => env('HILLSTONE_BASE_URL'),
        'timeout' => env('HILLSTONE_TIMEOUT', 30),
        'verify_ssl' => env('HILLSTONE_VERIFY_SSL', true),
        'connect_timeout' => env('HILLSTONE_CONNECT_TIMEOUT', 10),
        'read_timeout' => env('HILLSTONE_READ_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure authentication settings for connecting to the Hillstone
    | firewall API. The package uses cookie-based authentication with
    | automatic token refresh capabilities.
    |
    */
    'authentication' => [
        'username' => env('HILLSTONE_USERNAME'),
        'password' => env('HILLSTONE_PASSWORD'),
        'token_cache_ttl' => env('HILLSTONE_TOKEN_TTL', 1200), // 20 minutes in seconds
        'max_auth_attempts' => env('HILLSTONE_MAX_AUTH_ATTEMPTS', 3),
        'auth_retry_delay' => env('HILLSTONE_AUTH_RETRY_DELAY', 5), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Synchronization Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how the package synchronizes firewall objects
    | between the Hillstone API and your local database. Adjust these
    | values based on your system's performance requirements.
    |
    */
    'sync' => [
        'batch_size' => env('HILLSTONE_BATCH_SIZE', 100),
        'retry_attempts' => env('HILLSTONE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('HILLSTONE_RETRY_DELAY', 5), // seconds
        'retry_multiplier' => env('HILLSTONE_RETRY_MULTIPLIER', 2), // exponential backoff
        'max_retry_delay' => env('HILLSTONE_MAX_RETRY_DELAY', 60), // seconds
        'cleanup_after_days' => env('HILLSTONE_CLEANUP_DAYS', 30),
        'prevent_concurrent_syncs' => env('HILLSTONE_PREVENT_CONCURRENT_SYNCS', true),
        'sync_timeout' => env('HILLSTONE_SYNC_TIMEOUT', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to respect the Hillstone API's request limits
    | and prevent overwhelming the firewall system with too many requests.
    |
    */
    'rate_limiting' => [
        'enabled' => env('HILLSTONE_RATE_LIMITING_ENABLED', true),
        'requests_per_minute' => env('HILLSTONE_REQUESTS_PER_MINUTE', 60),
        'burst_limit' => env('HILLSTONE_BURST_LIMIT', 10),
        'backoff_strategy' => env('HILLSTONE_BACKOFF_STRATEGY', 'exponential'), // linear, exponential
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging settings for the Hillstone package. The package
    | provides comprehensive logging for debugging, monitoring, and
    | troubleshooting purposes.
    |
    */
    'logging' => [
        'channel' => env('HILLSTONE_LOG_CHANNEL', 'default'),
        'level' => env('HILLSTONE_LOG_LEVEL', 'info'),
        
        // Request/Response Logging
        'log_requests' => env('HILLSTONE_LOG_REQUESTS', false),
        'log_responses' => env('HILLSTONE_LOG_RESPONSES', false),
        'log_request_headers' => env('HILLSTONE_LOG_REQUEST_HEADERS', false),
        'log_response_headers' => env('HILLSTONE_LOG_RESPONSE_HEADERS', false),
        
        // Authentication Logging
        'log_authentication' => env('HILLSTONE_LOG_AUTHENTICATION', true),
        'log_authentication_cache' => env('HILLSTONE_LOG_AUTH_CACHE', false),
        'log_token_refresh' => env('HILLSTONE_LOG_TOKEN_REFRESH', true),
        
        // Sync Operations Logging
        'log_sync_operations' => env('HILLSTONE_LOG_SYNC_OPERATIONS', true),
        'log_batch_processing' => env('HILLSTONE_LOG_BATCH_PROCESSING', true),
        'log_object_processing' => env('HILLSTONE_LOG_OBJECT_PROCESSING', false),
        'log_database_operations' => env('HILLSTONE_LOG_DATABASE_OPERATIONS', false),
        
        // Performance Metrics Logging
        'log_performance_metrics' => env('HILLSTONE_LOG_PERFORMANCE_METRICS', false),
        'log_memory_usage' => env('HILLSTONE_LOG_MEMORY_USAGE', false),
        'log_execution_time' => env('HILLSTONE_LOG_EXECUTION_TIME', true),
        'log_throughput_metrics' => env('HILLSTONE_LOG_THROUGHPUT_METRICS', false),
        
        // Error Logging
        'log_stack_traces' => env('HILLSTONE_LOG_STACK_TRACES', true),
        'log_error_context' => env('HILLSTONE_LOG_ERROR_CONTEXT', true),
        'log_retry_attempts' => env('HILLSTONE_LOG_RETRY_ATTEMPTS', true),
        
        // Job and Command Logging
        'log_job_lifecycle' => env('HILLSTONE_LOG_JOB_LIFECYCLE', true),
        'log_command_execution' => env('HILLSTONE_LOG_COMMAND_EXECUTION', true),
        'log_queue_operations' => env('HILLSTONE_LOG_QUEUE_OPERATIONS', false),
        
        // Structured Logging Options
        'include_context_data' => env('HILLSTONE_INCLUDE_CONTEXT_DATA', true),
        'include_system_info' => env('HILLSTONE_INCLUDE_SYSTEM_INFO', false),
        'log_correlation_id' => env('HILLSTONE_LOG_CORRELATION_ID', false),
        
        // Log Filtering
        'exclude_sensitive_data' => env('HILLSTONE_EXCLUDE_SENSITIVE_DATA', true),
        'max_log_payload_size' => env('HILLSTONE_MAX_LOG_PAYLOAD_SIZE', 10240), // 10KB
        'truncate_long_messages' => env('HILLSTONE_TRUNCATE_LONG_MESSAGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event dispatching for the package. Events allow you to
    | extend functionality and integrate with other systems by listening
    | to sync lifecycle events.
    |
    */
    'events' => [
        'enabled' => env('HILLSTONE_EVENTS_ENABLED', true),
        'dispatch_sync_started' => env('HILLSTONE_DISPATCH_SYNC_STARTED', true),
        'dispatch_sync_completed' => env('HILLSTONE_DISPATCH_SYNC_COMPLETED', true),
        'dispatch_sync_failed' => env('HILLSTONE_DISPATCH_SYNC_FAILED', true),
        'dispatch_object_synced' => env('HILLSTONE_DISPATCH_OBJECT_SYNCED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database-specific settings for storing firewall objects.
    | These settings control table names, connection preferences, and
    | data retention policies.
    |
    */
    'database' => [
        'connection' => env('HILLSTONE_DB_CONNECTION', null), // null uses default
        'table_prefix' => env('HILLSTONE_TABLE_PREFIX', 'hillstone_'),
        'use_transactions' => env('HILLSTONE_USE_TRANSACTIONS', true),
        'chunk_size' => env('HILLSTONE_CHUNK_SIZE', 1000), // for bulk operations
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue settings for background job processing. The package
    | uses Laravel's queue system for asynchronous synchronization operations.
    |
    */
    'queue' => [
        'connection' => env('HILLSTONE_QUEUE_CONNECTION', null), // null uses default
        'queue_name' => env('HILLSTONE_QUEUE_NAME', 'hillstone-sync'),
        'job_timeout' => env('HILLSTONE_JOB_TIMEOUT', 300), // 5 minutes
        'job_tries' => env('HILLSTONE_JOB_TRIES', 3),
        'job_backoff' => env('HILLSTONE_JOB_BACKOFF', 60), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure validation rules and settings for firewall object data.
    | These settings help ensure data integrity and consistency.
    |
    */
    'validation' => [
        'strict_mode' => env('HILLSTONE_STRICT_VALIDATION', false),
        'validate_ip_addresses' => env('HILLSTONE_VALIDATE_IP_ADDRESSES', true),
        'validate_object_names' => env('HILLSTONE_VALIDATE_OBJECT_NAMES', true),
        'max_object_name_length' => env('HILLSTONE_MAX_OBJECT_NAME_LENGTH', 255),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching settings for authentication tokens and frequently
    | accessed data to improve performance and reduce API calls.
    |
    */
    'cache' => [
        'store' => env('HILLSTONE_CACHE_STORE', null), // null uses default
        'prefix' => env('HILLSTONE_CACHE_PREFIX', 'hillstone_'),
        'auth_token_ttl' => env('HILLSTONE_AUTH_TOKEN_CACHE_TTL', 1200), // 20 minutes
        'object_cache_ttl' => env('HILLSTONE_OBJECT_CACHE_TTL', 300), // 5 minutes
    ],
];