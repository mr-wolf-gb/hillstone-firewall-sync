# Configuration Guide

This guide provides detailed information about configuring the Hillstone Firewall Sync package for your Laravel application.

## Table of Contents

- [Configuration File Overview](#configuration-file-overview)
- [Connection Settings](#connection-settings)
- [Authentication Configuration](#authentication-configuration)
- [Synchronization Settings](#synchronization-settings)
- [Logging Configuration](#logging-configuration)
- [Event System Configuration](#event-system-configuration)
- [Environment Variables](#environment-variables)
- [Advanced Configuration](#advanced-configuration)
- [Configuration Validation](#configuration-validation)
- [Troubleshooting](#troubleshooting)

## Configuration File Overview

The package configuration is stored in `config/hillstone.php`. After installation, publish the configuration file:

```bash
php artisan vendor:publish --provider="MrWolfGb\HillstoneFirewallSync\Providers\HillstoneServiceProvider" --tag="config"
```

The configuration file is organized into logical sections:

```php
return [
    'connection' => [...],      // Firewall connection settings
    'authentication' => [...],  // Authentication configuration
    'sync' => [...],           // Synchronization behavior
    'logging' => [...],        // Logging preferences
    'events' => [...],         // Event system settings
];
```

## Connection Settings

### Basic Connection Configuration

```php
'connection' => [
    'domain' => env('HILLSTONE_DOMAIN'),
    'base_url' => env('HILLSTONE_BASE_URL'),
    'timeout' => env('HILLSTONE_TIMEOUT', 30),
    'verify_ssl' => env('HILLSTONE_VERIFY_SSL', true),
],
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `domain` | string | **required** | The domain name of your Hillstone firewall |
| `base_url` | string | **required** | Full API base URL including protocol |
| `timeout` | integer | `30` | Request timeout in seconds |
| `verify_ssl` | boolean | `true` | Whether to verify SSL certificates |

#### Environment Variables

```env
# Required
HILLSTONE_DOMAIN=firewall.company.com
HILLSTONE_BASE_URL=https://firewall.company.com/api

# Optional
HILLSTONE_TIMEOUT=30
HILLSTONE_VERIFY_SSL=true
```

#### Examples

**Production Environment:**
```env
HILLSTONE_DOMAIN=fw-prod.company.com
HILLSTONE_BASE_URL=https://fw-prod.company.com/api
HILLSTONE_TIMEOUT=45
HILLSTONE_VERIFY_SSL=true
```

**Development Environment:**
```env
HILLSTONE_DOMAIN=fw-dev.company.local
HILLSTONE_BASE_URL=https://fw-dev.company.local/api
HILLSTONE_TIMEOUT=15
HILLSTONE_VERIFY_SSL=false
```

## Authentication Configuration

### Basic Authentication Settings

```php
'authentication' => [
    'username' => env('HILLSTONE_USERNAME'),
    'password' => env('HILLSTONE_PASSWORD'),
    'token_cache_ttl' => env('HILLSTONE_TOKEN_TTL', 1200),
],
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `username` | string | **required** | Firewall API username |
| `password` | string | **required** | Firewall API password |
| `token_cache_ttl` | integer | `1200` | Token cache TTL in seconds (20 minutes) |

#### Environment Variables

```env
# Required
HILLSTONE_USERNAME=api_user
HILLSTONE_PASSWORD=secure_password

# Optional
HILLSTONE_TOKEN_TTL=1200
```

#### Security Considerations

- **Never commit credentials to version control**
- Use strong, unique passwords for API accounts
- Consider using Laravel's encryption for sensitive configuration
- Rotate credentials regularly
- Use dedicated service accounts with minimal required permissions

#### Token Management

The package automatically manages authentication tokens:

- Tokens are cached for the configured TTL
- Automatic re-authentication when tokens expire
- Failed authentication triggers exponential backoff
- Authentication events are logged for monitoring

## Synchronization Settings

### Basic Sync Configuration

```php
'sync' => [
    'batch_size' => env('HILLSTONE_BATCH_SIZE', 100),
    'retry_attempts' => env('HILLSTONE_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('HILLSTONE_RETRY_DELAY', 5),
    'cleanup_after_days' => env('HILLSTONE_CLEANUP_DAYS', 30),
],
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `batch_size` | integer | `100` | Number of objects to process per batch |
| `retry_attempts` | integer | `3` | Maximum retry attempts for failed operations |
| `retry_delay` | integer | `5` | Base delay between retries in seconds |
| `cleanup_after_days` | integer | `30` | Days after which to clean up old objects |

#### Environment Variables

```env
HILLSTONE_BATCH_SIZE=100
HILLSTONE_RETRY_ATTEMPTS=3
HILLSTONE_RETRY_DELAY=5
HILLSTONE_CLEANUP_DAYS=30
```

#### Performance Tuning

**High-Volume Environments:**
```env
HILLSTONE_BATCH_SIZE=500
HILLSTONE_RETRY_ATTEMPTS=5
HILLSTONE_RETRY_DELAY=2
```

**Low-Resource Environments:**
```env
HILLSTONE_BATCH_SIZE=25
HILLSTONE_RETRY_ATTEMPTS=2
HILLSTONE_RETRY_DELAY=10
```

#### Retry Logic

The package implements exponential backoff for retries:

1. First retry: `retry_delay` seconds
2. Second retry: `retry_delay * 2` seconds
3. Third retry: `retry_delay * 4` seconds
4. And so on...

## Logging Configuration

### Basic Logging Settings

```php
'logging' => [
    'channel' => env('HILLSTONE_LOG_CHANNEL', 'default'),
    'level' => env('HILLSTONE_LOG_LEVEL', 'info'),
],
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `channel` | string | `'default'` | Laravel logging channel to use |
| `level` | string | `'info'` | Minimum log level (debug, info, warning, error) |

#### Environment Variables

```env
HILLSTONE_LOG_CHANNEL=hillstone
HILLSTONE_LOG_LEVEL=info
```

#### Custom Log Channel

Create a dedicated log channel in `config/logging.php`:

```php
'channels' => [
    'hillstone' => [
        'driver' => 'daily',
        'path' => storage_path('logs/hillstone.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'days' => 14,
    ],
],
```

#### Log Levels

- **debug**: Detailed debugging information
- **info**: General information about operations
- **warning**: Warning conditions that don't stop execution
- **error**: Error conditions that may stop execution

#### What Gets Logged

- Authentication attempts and results
- API request/response details (debug level)
- Sync operation progress and results
- Database operation performance
- Job processing status
- Error conditions with stack traces

## Event System Configuration

### Basic Event Settings

```php
'events' => [
    'enabled' => env('HILLSTONE_EVENTS_ENABLED', true),
],
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | boolean | `true` | Whether to dispatch package events |

#### Environment Variables

```env
HILLSTONE_EVENTS_ENABLED=true
```

#### Available Events

- `SyncStarted`: Fired when sync operations begin
- `SyncCompleted`: Fired when sync operations complete successfully
- `SyncFailed`: Fired when sync operations fail
- `ObjectSynced`: Fired when individual objects are synchronized

#### Disabling Events

Set `HILLSTONE_EVENTS_ENABLED=false` to disable event dispatching for performance in high-volume environments.

## Environment Variables

### Complete Environment Variable Reference

```env
# Connection (Required)
HILLSTONE_DOMAIN=your-firewall-domain.com
HILLSTONE_BASE_URL=https://your-firewall-domain.com/api

# Authentication (Required)
HILLSTONE_USERNAME=your-username
HILLSTONE_PASSWORD=your-password

# Connection (Optional)
HILLSTONE_TIMEOUT=30
HILLSTONE_VERIFY_SSL=true

# Authentication (Optional)
HILLSTONE_TOKEN_TTL=1200

# Synchronization (Optional)
HILLSTONE_BATCH_SIZE=100
HILLSTONE_RETRY_ATTEMPTS=3
HILLSTONE_RETRY_DELAY=5
HILLSTONE_CLEANUP_DAYS=30

# Logging (Optional)
HILLSTONE_LOG_CHANNEL=default
HILLSTONE_LOG_LEVEL=info

# Events (Optional)
HILLSTONE_EVENTS_ENABLED=true
```

### Environment-Specific Examples

#### Production Environment

```env
# Production - High Security, High Performance
HILLSTONE_DOMAIN=fw-prod.company.com
HILLSTONE_BASE_URL=https://fw-prod.company.com/api
HILLSTONE_USERNAME=prod_api_user
HILLSTONE_PASSWORD=very_secure_password
HILLSTONE_TIMEOUT=45
HILLSTONE_VERIFY_SSL=true
HILLSTONE_TOKEN_TTL=1800
HILLSTONE_BATCH_SIZE=200
HILLSTONE_RETRY_ATTEMPTS=5
HILLSTONE_RETRY_DELAY=3
HILLSTONE_CLEANUP_DAYS=90
HILLSTONE_LOG_CHANNEL=hillstone
HILLSTONE_LOG_LEVEL=warning
HILLSTONE_EVENTS_ENABLED=true
```

#### Staging Environment

```env
# Staging - Balanced Settings
HILLSTONE_DOMAIN=fw-staging.company.com
HILLSTONE_BASE_URL=https://fw-staging.company.com/api
HILLSTONE_USERNAME=staging_api_user
HILLSTONE_PASSWORD=staging_password
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

#### Development Environment

```env
# Development - Verbose Logging, Relaxed Security
HILLSTONE_DOMAIN=fw-dev.company.local
HILLSTONE_BASE_URL=https://fw-dev.company.local/api
HILLSTONE_USERNAME=dev_api_user
HILLSTONE_PASSWORD=dev_password
HILLSTONE_TIMEOUT=15
HILLSTONE_VERIFY_SSL=false
HILLSTONE_TOKEN_TTL=600
HILLSTONE_BATCH_SIZE=25
HILLSTONE_RETRY_ATTEMPTS=2
HILLSTONE_RETRY_DELAY=2
HILLSTONE_CLEANUP_DAYS=7
HILLSTONE_LOG_CHANNEL=single
HILLSTONE_LOG_LEVEL=debug
HILLSTONE_EVENTS_ENABLED=true
```

## Advanced Configuration

### Custom Configuration Values

You can extend the configuration by modifying `config/hillstone.php`:

```php
return [
    // Standard configuration sections...
    
    // Custom extensions
    'custom' => [
        'object_filters' => [
            'exclude_predefined' => env('HILLSTONE_EXCLUDE_PREDEFINED', false),
            'include_patterns' => explode(',', env('HILLSTONE_INCLUDE_PATTERNS', '')),
            'exclude_patterns' => explode(',', env('HILLSTONE_EXCLUDE_PATTERNS', '')),
        ],
        
        'performance' => [
            'enable_caching' => env('HILLSTONE_ENABLE_CACHING', true),
            'cache_ttl' => env('HILLSTONE_CACHE_TTL', 3600),
            'parallel_requests' => env('HILLSTONE_PARALLEL_REQUESTS', 5),
        ],
        
        'notifications' => [
            'slack_webhook' => env('HILLSTONE_SLACK_WEBHOOK'),
            'email_recipients' => explode(',', env('HILLSTONE_EMAIL_RECIPIENTS', '')),
            'notify_on_failure' => env('HILLSTONE_NOTIFY_ON_FAILURE', true),
        ],
    ],
];
```

### Runtime Configuration

Access configuration values in your code:

```php
// Get configuration values
$domain = config('hillstone.connection.domain');
$batchSize = config('hillstone.sync.batch_size');

// Check if events are enabled
if (config('hillstone.events.enabled')) {
    // Dispatch events
}

// Get custom configuration
$filters = config('hillstone.custom.object_filters', []);
```

## Configuration Validation

### Built-in Validation

The package validates configuration on startup:

```php
// Check required configuration
if (empty(config('hillstone.connection.domain'))) {
    throw new InvalidConfigurationException('HILLSTONE_DOMAIN is required');
}

// Validate numeric values
if (config('hillstone.sync.batch_size') < 1) {
    throw new InvalidConfigurationException('Batch size must be greater than 0');
}
```

### Custom Validation

Add custom validation in your service provider:

```php
public function boot()
{
    $this->validateHillstoneConfiguration();
}

private function validateHillstoneConfiguration()
{
    $config = config('hillstone');
    
    // Validate URL format
    if (!filter_var($config['connection']['base_url'], FILTER_VALIDATE_URL)) {
        throw new InvalidConfigurationException('Invalid base URL format');
    }
    
    // Validate timeout range
    $timeout = $config['connection']['timeout'];
    if ($timeout < 5 || $timeout > 300) {
        throw new InvalidConfigurationException('Timeout must be between 5 and 300 seconds');
    }
}
```

### Configuration Testing

Test your configuration with Artisan commands:

```bash
# Test connection
php artisan hillstone:test-connection

# Validate configuration
php artisan hillstone:validate-config

# Show current configuration
php artisan hillstone:config-status
```

## Troubleshooting

### Common Configuration Issues

#### 1. Authentication Failures

**Problem**: `CouldNotAuthenticateException` errors

**Solutions**:
- Verify `HILLSTONE_USERNAME` and `HILLSTONE_PASSWORD` are correct
- Check if the API user account is active and has proper permissions
- Ensure the firewall is accessible from your application server
- Verify SSL certificate if `HILLSTONE_VERIFY_SSL=true`

```bash
# Test authentication
php artisan hillstone:test-auth
```

#### 2. Connection Timeouts

**Problem**: Requests timing out or failing

**Solutions**:
- Increase `HILLSTONE_TIMEOUT` value
- Check network connectivity to the firewall
- Verify firewall is not blocking your application's IP
- Check if firewall API service is running

```bash
# Test connection
curl -v https://your-firewall-domain.com/api
```

#### 3. SSL Certificate Issues

**Problem**: SSL verification failures

**Solutions**:
- Set `HILLSTONE_VERIFY_SSL=false` for development (not recommended for production)
- Install proper SSL certificates on the firewall
- Update your system's CA certificate bundle

#### 4. Performance Issues

**Problem**: Slow synchronization or high memory usage

**Solutions**:
- Reduce `HILLSTONE_BATCH_SIZE` for memory-constrained environments
- Increase `HILLSTONE_BATCH_SIZE` for high-performance environments
- Adjust `HILLSTONE_RETRY_DELAY` based on API response times
- Enable Redis for better queue performance

#### 5. Database Issues

**Problem**: Migration or model errors

**Solutions**:
- Ensure database user has proper permissions
- Run migrations: `php artisan migrate`
- Check database connection in `config/database.php`
- Verify table indexes are created properly

### Debug Mode

Enable debug logging for troubleshooting:

```env
HILLSTONE_LOG_LEVEL=debug
```

This will log:
- Detailed API request/response data
- Database query information
- Authentication token details
- Job processing steps

### Configuration Backup

Always backup your configuration before making changes:

```bash
# Backup current configuration
cp .env .env.backup
cp config/hillstone.php config/hillstone.php.backup
```

### Getting Help

If you continue to experience issues:

1. Check the [troubleshooting guide](troubleshooting.md)
2. Enable debug logging and review logs
3. Test individual components (auth, connection, sync)
4. Open an issue with configuration details (remove sensitive data)
5. Contact support with log files and configuration

### Configuration Checklist

Before deploying to production:

- [ ] All required environment variables are set
- [ ] SSL certificates are properly configured
- [ ] Database migrations have been run
- [ ] Queue workers are configured and running
- [ ] Log channels are properly configured
- [ ] Authentication credentials are secure and rotated
- [ ] Batch sizes are optimized for your environment
- [ ] Retry settings are appropriate for your network
- [ ] Cleanup policies are configured
- [ ] Monitoring and alerting are set up