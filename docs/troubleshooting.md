# Troubleshooting Guide

This guide helps you diagnose and resolve common issues with the Hillstone Firewall Sync package.

## Table of Contents

- [Quick Diagnostics](#quick-diagnostics)
- [Authentication Issues](#authentication-issues)
- [Connection Problems](#connection-problems)
- [Synchronization Failures](#synchronization-failures)
- [Database Issues](#database-issues)
- [Queue and Job Problems](#queue-and-job-problems)
- [Performance Issues](#performance-issues)
- [Logging and Monitoring](#logging-and-monitoring)
- [Common Error Messages](#common-error-messages)
- [Debug Tools](#debug-tools)

## Quick Diagnostics

### Health Check Commands

Run these commands to quickly identify issues:

```bash
# Test overall package health
php artisan hillstone:health-check

# Test firewall connection
php artisan hillstone:test-connection

# Test authentication
php artisan hillstone:test-auth

# Validate configuration
php artisan hillstone:validate-config

# Check sync status
php artisan hillstone:sync-status
```

### Configuration Verification

Verify your configuration is correct:

```bash
# Show current configuration (sensitive data masked)
php artisan hillstone:config-status

# Test environment variables
php artisan tinker
>>> config('hillstone.connection.domain')
>>> config('hillstone.authentication.username')
```

## Authentication Issues

### CouldNotAuthenticateException

**Symptoms:**
- `CouldNotAuthenticateException` thrown during operations
- "Authentication failed" messages in logs
- 401 Unauthorized responses from API

**Diagnostic Steps:**

1. **Verify Credentials:**
   ```bash
   # Check if credentials are set
   php artisan tinker
   >>> config('hillstone.authentication.username')
   >>> !empty(config('hillstone.authentication.password'))
   ```

2. **Test Manual Authentication:**
   ```bash
   # Test authentication directly
   php artisan hillstone:test-auth --verbose
   ```

3. **Check Firewall User Account:**
   - Verify the API user exists on the firewall
   - Ensure the account is not locked or expired
   - Check user permissions for API access

**Solutions:**

1. **Update Credentials:**
   ```env
   HILLSTONE_USERNAME=correct_username
   HILLSTONE_PASSWORD=correct_password
   ```

2. **Clear Configuration Cache:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Increase Token TTL:**
   ```env
   HILLSTONE_TOKEN_TTL=1800  # 30 minutes
   ```

4. **Check User Permissions:**
   - Ensure API user has "API Access" permission
   - Verify user can access address book objects
   - Check if user account has proper role assignments

### Token Expiration Issues

**Symptoms:**
- Intermittent authentication failures
- "Token expired" messages
- Operations fail after running for extended periods

**Solutions:**

1. **Adjust Token TTL:**
   ```env
   # Reduce TTL to force more frequent refresh
   HILLSTONE_TOKEN_TTL=900  # 15 minutes
   ```

2. **Enable Token Refresh Logging:**
   ```env
   HILLSTONE_LOG_LEVEL=debug
   ```

3. **Monitor Token Usage:**
   ```php
   // In your code
   if (!HillstoneFirewall::isAuthenticated()) {
       Log::warning('Authentication token expired, re-authenticating');
   }
   ```

## Connection Problems

### Network Connectivity Issues

**Symptoms:**
- Connection timeouts
- "Connection refused" errors
- DNS resolution failures

**Diagnostic Steps:**

1. **Test Network Connectivity:**
   ```bash
   # Test basic connectivity
   ping your-firewall-domain.com
   
   # Test HTTPS connectivity
   curl -v https://your-firewall-domain.com/api
   
   # Test from application server
   php artisan hillstone:test-connection
   ```

2. **Check DNS Resolution:**
   ```bash
   nslookup your-firewall-domain.com
   dig your-firewall-domain.com
   ```

3. **Verify Firewall Configuration:**
   - Check if API service is enabled
   - Verify HTTPS is configured properly
   - Ensure firewall is not blocking your IP

**Solutions:**

1. **Adjust Timeout Settings:**
   ```env
   HILLSTONE_TIMEOUT=60  # Increase timeout
   ```

2. **Use IP Address Instead of Domain:**
   ```env
   HILLSTONE_DOMAIN=192.168.1.100
   HILLSTONE_BASE_URL=https://192.168.1.100/api
   ```

3. **Disable SSL Verification (Development Only):**
   ```env
   HILLSTONE_VERIFY_SSL=false
   ```

### SSL Certificate Issues

**Symptoms:**
- SSL verification failures
- "Certificate verify failed" errors
- CURL SSL errors

**Diagnostic Steps:**

1. **Test SSL Certificate:**
   ```bash
   # Check certificate details
   openssl s_client -connect your-firewall-domain.com:443
   
   # Test with curl
   curl -v https://your-firewall-domain.com/api
   ```

2. **Check Certificate Chain:**
   ```bash
   # Verify certificate chain
   curl --insecure -v https://your-firewall-domain.com/api
   ```

**Solutions:**

1. **Update CA Bundle:**
   ```bash
   # Update system CA certificates
   sudo apt-get update && sudo apt-get install ca-certificates
   ```

2. **Configure Custom CA Bundle:**
   ```php
   // In config/hillstone.php
   'connection' => [
       'verify_ssl' => true,
       'ca_bundle' => '/path/to/ca-bundle.crt',
   ],
   ```

3. **Disable SSL Verification (Not Recommended for Production):**
   ```env
   HILLSTONE_VERIFY_SSL=false
   ```

## Synchronization Failures

### Partial Sync Failures

**Symptoms:**
- Some objects sync successfully, others fail
- "Sync completed with errors" messages
- Inconsistent object counts

**Diagnostic Steps:**

1. **Check Sync Logs:**
   ```bash
   # View recent sync logs
   php artisan hillstone:sync-status --detailed
   
   # Check application logs
   tail -f storage/logs/laravel.log | grep -i hillstone
   ```

2. **Test Individual Object Sync:**
   ```bash
   # Try syncing a specific object
   php artisan hillstone:sync-object "object-name" --verbose
   ```

**Solutions:**

1. **Reduce Batch Size:**
   ```env
   HILLSTONE_BATCH_SIZE=25  # Smaller batches
   ```

2. **Increase Retry Attempts:**
   ```env
   HILLSTONE_RETRY_ATTEMPTS=5
   HILLSTONE_RETRY_DELAY=10
   ```

3. **Enable Detailed Logging:**
   ```env
   HILLSTONE_LOG_LEVEL=debug
   ```

### Data Validation Errors

**Symptoms:**
- "Invalid data format" errors
- Database constraint violations
- Missing required fields

**Solutions:**

1. **Check Object Data Structure:**
   ```php
   // Debug object data
   $client = app(HillstoneClientInterface::class);
   $objects = $client->getAllAddressBookObjects();
   dd($objects->first());
   ```

2. **Validate Database Schema:**
   ```bash
   # Re-run migrations
   php artisan migrate:fresh
   php artisan migrate
   ```

3. **Clear Corrupted Data:**
   ```bash
   # Clear and re-sync
   php artisan hillstone:cleanup --force
   php artisan hillstone:sync-all
   ```

## Database Issues

### Migration Failures

**Symptoms:**
- Migration errors during installation
- "Table already exists" errors
- Foreign key constraint failures

**Solutions:**

1. **Check Database Permissions:**
   ```sql
   -- Verify user permissions
   SHOW GRANTS FOR 'your_db_user'@'localhost';
   ```

2. **Run Migrations Step by Step:**
   ```bash
   # Run migrations individually
   php artisan migrate --path=vendor/your-vendor/hillstone-firewall-sync/database/migrations/create_hillstone_objects_table.php
   ```

3. **Reset and Re-run:**
   ```bash
   # Reset migrations (WARNING: This will drop tables)
   php artisan migrate:rollback --path=vendor/your-vendor/hillstone-firewall-sync/database/migrations
   php artisan migrate --path=vendor/your-vendor/hillstone-firewall-sync/database/migrations
   ```

### Query Performance Issues

**Symptoms:**
- Slow database queries
- High memory usage during sync
- Database connection timeouts

**Solutions:**

1. **Add Database Indexes:**
   ```sql
   -- Add missing indexes
   CREATE INDEX idx_hillstone_objects_name ON hillstone_objects(name);
   CREATE INDEX idx_hillstone_objects_last_synced ON hillstone_objects(last_synced_at);
   ```

2. **Optimize Database Configuration:**
   ```ini
   # In my.cnf (MySQL)
   innodb_buffer_pool_size = 1G
   max_connections = 200
   query_cache_size = 64M
   ```

3. **Use Database Connection Pooling:**
   ```php
   // In config/database.php
   'mysql' => [
       'options' => [
           PDO::ATTR_PERSISTENT => true,
       ],
   ],
   ```

## Queue and Job Problems

### Jobs Not Processing

**Symptoms:**
- Sync jobs remain in "pending" status
- Queue workers not processing jobs
- Jobs fail silently

**Diagnostic Steps:**

1. **Check Queue Status:**
   ```bash
   # Check queue status
   php artisan queue:work --once
   
   # Monitor queue
   php artisan horizon:status  # If using Horizon
   ```

2. **Verify Queue Configuration:**
   ```bash
   php artisan tinker
   >>> config('queue.default')
   >>> config('queue.connections.redis')
   ```

**Solutions:**

1. **Start Queue Workers:**
   ```bash
   # Start queue worker
   php artisan queue:work
   
   # Start with specific queue
   php artisan queue:work --queue=hillstone
   ```

2. **Clear Failed Jobs:**
   ```bash
   # View failed jobs
   php artisan queue:failed
   
   # Retry failed jobs
   php artisan queue:retry all
   
   # Clear failed jobs
   php artisan queue:flush
   ```

3. **Configure Supervisor (Production):**
   ```ini
   [program:laravel-worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /path/to/artisan queue:work --sleep=3 --tries=3
   autostart=true
   autorestart=true
   user=www-data
   numprocs=8
   redirect_stderr=true
   stdout_logfile=/path/to/worker.log
   ```

### Job Memory Issues

**Symptoms:**
- "Allowed memory size exhausted" errors
- Jobs killed by system
- Inconsistent job completion

**Solutions:**

1. **Increase PHP Memory Limit:**
   ```ini
   # In php.ini
   memory_limit = 512M
   ```

2. **Reduce Batch Size:**
   ```env
   HILLSTONE_BATCH_SIZE=25
   ```

3. **Use Job Chunking:**
   ```php
   // Process in smaller chunks
   $objects->chunk(50, function ($chunk) {
       SyncSpecificObjectJob::dispatch($chunk);
   });
   ```

## Performance Issues

### Slow Synchronization

**Symptoms:**
- Sync operations take excessive time
- High CPU or memory usage
- API rate limiting errors

**Solutions:**

1. **Optimize Batch Processing:**
   ```env
   # Increase batch size for better performance
   HILLSTONE_BATCH_SIZE=200
   
   # Reduce retry delay
   HILLSTONE_RETRY_DELAY=2
   ```

2. **Enable Parallel Processing:**
   ```php
   // Dispatch multiple jobs
   for ($i = 0; $i < $totalBatches; $i++) {
       SyncBatchJob::dispatch($i);
   }
   ```

3. **Use Database Optimization:**
   ```sql
   -- Optimize tables
   OPTIMIZE TABLE hillstone_objects;
   OPTIMIZE TABLE hillstone_object_data;
   ```

### Memory Usage Issues

**Solutions:**

1. **Use Lazy Collections:**
   ```php
   // Instead of get(), use cursor()
   HillstoneObject::cursor()->each(function ($object) {
       // Process object
   });
   ```

2. **Clear Model Cache:**
   ```php
   // Clear Eloquent model cache periodically
   if ($processedCount % 1000 === 0) {
       gc_collect_cycles();
   }
   ```

## Logging and Monitoring

### Enable Debug Logging

```env
HILLSTONE_LOG_LEVEL=debug
HILLSTONE_LOG_CHANNEL=hillstone
```

### Custom Log Channel

```php
// In config/logging.php
'channels' => [
    'hillstone' => [
        'driver' => 'daily',
        'path' => storage_path('logs/hillstone.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

### Monitor Key Metrics

```php
// Add monitoring to your sync operations
Log::info('Sync started', [
    'operation' => 'full_sync',
    'memory_usage' => memory_get_usage(true),
    'time' => now(),
]);
```

## Common Error Messages

### "Class 'HillstoneFirewall' not found"

**Cause:** Package not properly installed or service provider not registered.

**Solution:**
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### "SQLSTATE[42S02]: Base table or field doesn't exist"

**Cause:** Database migrations not run.

**Solution:**
```bash
php artisan migrate
```

### "cURL error 60: SSL certificate problem"

**Cause:** SSL certificate verification failure.

**Solution:**
```env
HILLSTONE_VERIFY_SSL=false  # Development only
```

### "Maximum execution time exceeded"

**Cause:** PHP execution time limit reached.

**Solution:**
```ini
# In php.ini
max_execution_time = 300
```

Or use queue processing:
```bash
php artisan queue:work
```

## Debug Tools

### Package Debug Commands

```bash
# Test all components
php artisan hillstone:debug

# Test specific component
php artisan hillstone:debug --component=auth
php artisan hillstone:debug --component=connection
php artisan hillstone:debug --component=database
```

### Laravel Debug Tools

```bash
# Enable debug mode
APP_DEBUG=true

# Use Telescope for request monitoring
composer require laravel/telescope
php artisan telescope:install
```

### Custom Debug Helpers

```php
// Add to your code for debugging
if (app()->environment('local')) {
    \DB::enableQueryLog();
    
    // Your sync code here
    
    dd(\DB::getQueryLog());
}
```

## Getting Additional Help

If these troubleshooting steps don't resolve your issue:

1. **Enable debug logging** and collect relevant log entries
2. **Document your configuration** (remove sensitive data)
3. **Note the exact error messages** and when they occur
4. **Test with minimal configuration** to isolate the issue
5. **Check the GitHub issues** for similar problems
6. **Open a new issue** with detailed information

### Information to Include in Support Requests

- Laravel version
- PHP version
- Package version
- Operating system
- Firewall model and firmware version
- Configuration (sanitized)
- Error messages and stack traces
- Steps to reproduce the issue

### Emergency Procedures

If you need to quickly disable the package:

1. **Stop queue workers:**
   ```bash
   sudo supervisorctl stop laravel-worker:*
   ```

2. **Disable package in service provider:**
   ```php
   // In AppServiceProvider
   public function register()
   {
       if (config('app.disable_hillstone', false)) {
           return;
       }
       // Normal registration
   }
   ```

3. **Set emergency flag:**
   ```env
   APP_DISABLE_HILLSTONE=true
   ```