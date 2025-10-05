# Hillstone Firewall Sync

A comprehensive Laravel package for integrating with Hillstone NG firewall systems. This package provides seamless synchronization of firewall objects (address book entries) with robust API integration, background job processing, and command-line tools.

## Features

- 🔐 **Secure Authentication** - Cookie-based authentication with automatic token refresh
- 🔄 **Background Synchronization** - Asynchronous processing using Laravel queues
- 📊 **Database Integration** - Normalized storage with optimized relationships
- 🎯 **Selective Sync** - Sync all objects or target specific ones
- 📝 **Comprehensive Logging** - Detailed logging and monitoring capabilities
- 🎨 **Artisan Commands** - CLI tools for manual operations
- 🔔 **Event System** - Event-driven architecture for extensibility
- 🏗️ **Laravel Integration** - Follows Laravel conventions and best practices

## Requirements

- PHP 8.1 or higher
- Laravel 9.0 or higher
- MySQL 5.7+ or PostgreSQL 10+
- Redis (for queue processing)

## Installation

### 1. Install via Composer

```bash
composer require mr-wolf-gb/hillstone-firewall-sync
```

### 2. Publish Configuration and Migrations

```bash
# Publish configuration file
php artisan vendor:publish --provider="MrWolfGb\HillstoneFirewallSync\Providers\HillstoneServiceProvider" --tag="config"

# Publish and run migrations
php artisan vendor:publish --provider="MrWolfGb\HillstoneFirewallSync\Providers\HillstoneServiceProvider" --tag="migrations"
php artisan migrate
```

### 3. Configure Environment Variables

Add the following variables to your `.env` file:

```env
# Hillstone Firewall Connection
HILLSTONE_DOMAIN=your-firewall-domain.com
HILLSTONE_BASE_URL=https://your-firewall-domain.com/api
HILLSTONE_USERNAME=your-username
HILLSTONE_PASSWORD=your-password

# Optional Configuration
HILLSTONE_TIMEOUT=30
HILLSTONE_VERIFY_SSL=true
HILLSTONE_TOKEN_TTL=1200
HILLSTONE_BATCH_SIZE=100
HILLSTONE_RETRY_ATTEMPTS=3
HILLSTONE_RETRY_DELAY=5
HILLSTONE_CLEANUP_DAYS=30
HILLSTONE_LOG_CHANNEL=default
HILLSTONE_LOG_LEVEL=info
HILLSTONE_EVENTS_ENABLED=true
```

### 4. Configure Queue Processing

Ensure your Laravel application is configured for queue processing:

```bash
# Start queue worker
php artisan queue:work
```

## Usage

### Using the Facade

The package provides a convenient facade for common operations:

```php
use MrWolfGb\HillstoneFirewallSync\Facades\HillstoneFirewall;

// Sync all firewall objects
$result = HillstoneFirewall::syncAll();

// Sync a specific object
$result = HillstoneFirewall::syncObject('web-servers');

// Get last sync status
$status = HillstoneFirewall::getLastSyncStatus();

// Check if authenticated
$isAuthenticated = HillstoneFirewall::isAuthenticated();
```

### Using Service Classes

For more control, inject the service classes directly:

```php
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;

class FirewallController extends Controller
{
    public function __construct(
        private SyncServiceInterface $syncService,
        private HillstoneClientInterface $client
    ) {}

    public function syncFirewallData()
    {
        try {
            $result = $this->syncService->syncAll();
            
            return response()->json([
                'success' => true,
                'objects_processed' => $result->getObjectsProcessed(),
                'objects_created' => $result->getObjectsCreated(),
                'objects_updated' => $result->getObjectsUpdated(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFirewallObjects()
    {
        $objects = $this->client->getAllAddressBookObjects();
        return response()->json($objects);
    }
}
```

### Working with Models

Query synchronized firewall objects using Eloquent models:

```php
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectData;

// Get all firewall objects
$objects = HillstoneObject::with('objectData.ipAddresses')->get();

// Find specific object
$webServers = HillstoneObject::where('name', 'web-servers')->first();

// Get objects synced in the last hour
$recentObjects = HillstoneObject::where('last_synced_at', '>=', now()->subHour())->get();

// Search by IP address
$objectsWithIP = HillstoneObjectData::whereHas('ipAddresses', function ($query) {
    $query->where('ip_address', '192.168.1.100');
})->get();
```

### Background Jobs

Dispatch synchronization jobs for asynchronous processing:

```php
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncAllObjectsJob;
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncSpecificObjectJob;

// Dispatch full sync job
SyncAllObjectsJob::dispatch();

// Dispatch specific object sync
SyncSpecificObjectJob::dispatch('web-servers');

// Schedule cleanup job
CleanupOldObjectsJob::dispatch();
```

### Event Handling

Listen to synchronization events:

```php
use MrWolfGb\HillstoneFirewallSync\Events\SyncStarted;
use MrWolfGb\HillstoneFirewallSync\Events\SyncCompleted;
use MrWolfGb\HillstoneFirewallSync\Events\ObjectSynced;

// In your EventServiceProvider
protected $listen = [
    SyncStarted::class => [
        SendSyncNotification::class,
    ],
    SyncCompleted::class => [
        LogSyncCompletion::class,
        UpdateDashboard::class,
    ],
    ObjectSynced::class => [
        ProcessObjectUpdate::class,
    ],
];
```

## Artisan Commands

The package provides several Artisan commands for manual operations:

### Sync All Objects

```bash
# Basic sync
php artisan hillstone:sync-all

# With verbose output
php artisan hillstone:sync-all --verbose

# Dry run (show what would be synced)
php artisan hillstone:sync-all --dry-run
```

### Sync Specific Object

```bash
# Sync a specific object
php artisan hillstone:sync-object web-servers

# With verbose output
php artisan hillstone:sync-object web-servers --verbose
```

### Check Sync Status

```bash
# Display sync status and statistics
php artisan hillstone:sync-status

# Show detailed information
php artisan hillstone:sync-status --detailed
```

### Cleanup Old Objects

```bash
# Clean up objects older than configured days
php artisan hillstone:cleanup

# Clean up objects older than specific days
php artisan hillstone:cleanup --days=7
```

## Configuration

The package configuration file (`config/hillstone.php`) provides extensive customization options:

```php
return [
    'connection' => [
        'domain' => env('HILLSTONE_DOMAIN'),
        'base_url' => env('HILLSTONE_BASE_URL'),
        'timeout' => env('HILLSTONE_TIMEOUT', 30),
        'verify_ssl' => env('HILLSTONE_VERIFY_SSL', true),
    ],
    
    'authentication' => [
        'username' => env('HILLSTONE_USERNAME'),
        'password' => env('HILLSTONE_PASSWORD'),
        'token_cache_ttl' => env('HILLSTONE_TOKEN_TTL', 1200),
    ],
    
    'sync' => [
        'batch_size' => env('HILLSTONE_BATCH_SIZE', 100),
        'retry_attempts' => env('HILLSTONE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('HILLSTONE_RETRY_DELAY', 5),
        'cleanup_after_days' => env('HILLSTONE_CLEANUP_DAYS', 30),
    ],
    
    'logging' => [
        'channel' => env('HILLSTONE_LOG_CHANNEL', 'default'),
        'level' => env('HILLSTONE_LOG_LEVEL', 'info'),
    ],
    
    'events' => [
        'enabled' => env('HILLSTONE_EVENTS_ENABLED', true),
    ],
];
```

## Error Handling

The package provides comprehensive error handling with specific exception types:

```php
use MrWolfGb\HillstoneFirewallSync\Exceptions\CouldNotAuthenticateException;
use MrWolfGb\HillstoneFirewallSync\Exceptions\ApiRequestException;
use MrWolfGb\HillstoneFirewallSync\Exceptions\SyncException;

try {
    HillstoneFirewall::syncAll();
} catch (CouldNotAuthenticateException $e) {
    // Handle authentication failures
    Log::error('Firewall authentication failed: ' . $e->getMessage());
} catch (ApiRequestException $e) {
    // Handle API communication errors
    Log::error('API request failed: ' . $e->getMessage());
} catch (SyncException $e) {
    // Handle synchronization errors
    Log::error('Sync operation failed: ' . $e->getMessage());
}
```

## Testing

The package is designed with testability in mind. Here's how to test your integration:

```php
use MrWolfGb\HillstoneFirewallSync\Facades\HillstoneFirewall;
use MrWolfGb\HillstoneFirewallSync\Events\SyncCompleted;

class FirewallSyncTest extends TestCase
{
    public function test_can_sync_firewall_objects()
    {
        Event::fake();
        
        $result = HillstoneFirewall::syncAll();
        
        $this->assertTrue($result->isSuccessful());
        Event::assertDispatched(SyncCompleted::class);
    }
}
```

## Monitoring and Logging

The package provides comprehensive logging for monitoring and troubleshooting:

- **Authentication Events**: Login attempts, token refresh, failures
- **API Operations**: Request/response logging, rate limiting, errors
- **Sync Operations**: Progress tracking, object counts, performance metrics
- **Database Operations**: Query performance, transaction rollbacks
- **Job Processing**: Queue status, retry attempts, failures

## Performance Considerations

- **Batch Processing**: Large datasets are processed in configurable batches
- **Connection Reuse**: HTTP connections are reused for efficiency
- **Database Optimization**: Strategic indexing and bulk operations
- **Memory Management**: Large syncs are chunked to prevent memory exhaustion
- **Rate Limiting**: Respects API limits with intelligent queuing

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Support

For support, please:

1. Check the [troubleshooting guide](docs/troubleshooting.md)
2. Review the [configuration documentation](docs/configuration.md)
3. Open an issue on GitHub

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes and version history.
