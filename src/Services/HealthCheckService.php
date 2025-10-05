<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Exceptions\CouldNotAuthenticateException;
use MrWolfGb\HillstoneFirewallSync\Exceptions\ApiRequestException;
use Exception;
use Carbon\Carbon;

/**
 * Health Check Service
 * 
 * Provides comprehensive health checks for the Hillstone Firewall Sync package.
 */
class HealthCheckService
{
    protected HillstoneClientInterface $client;
    protected array $healthResults = [];
    protected bool $verbose = false;

    public function __construct(HillstoneClientInterface $client, bool $verbose = false)
    {
        $this->client = $client;
        $this->verbose = $verbose;
    }

    /**
     * Run comprehensive health checks
     *
     * @return array
     */
    public function runHealthChecks(): array
    {
        $this->healthResults = [];

        $this->log('Starting Hillstone Firewall Sync Health Checks...');

        // Health Check 1: Database Connectivity
        $this->checkDatabaseConnectivity();

        // Health Check 2: Configuration Validity
        $this->checkConfigurationValidity();

        // Health Check 3: Firewall API Connectivity
        $this->checkFirewallApiConnectivity();

        // Health Check 4: Authentication
        $this->checkAuthentication();

        // Health Check 5: Database Tables and Data
        $this->checkDatabaseTablesAndData();

        // Health Check 6: Cache System
        $this->checkCacheSystem();

        // Health Check 7: Recent Sync Status
        $this->checkRecentSyncStatus();

        // Health Check 8: System Resources
        $this->checkSystemResources();

        $this->log('Health checks completed.');

        return $this->healthResults;
    }

    /**
     * Check database connectivity
     */
    protected function checkDatabaseConnectivity(): void
    {
        $this->log('Checking database connectivity...');

        try {
            DB::connection()->getPdo();
            $this->addHealthResult('Database Connection', 'healthy', 'Database connection is working');
        } catch (Exception $e) {
            $this->addHealthResult('Database Connection', 'unhealthy', 'Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Check configuration validity
     */
    protected function checkConfigurationValidity(): void
    {
        $this->log('Checking configuration validity...');

        try {
            $config = config('hillstone');
            
            if (empty($config)) {
                $this->addHealthResult('Configuration', 'unhealthy', 'Configuration not found or empty');
                return;
            }

            // Check required configuration values
            $requiredConfigs = [
                'connection.domain' => 'Firewall domain',
                'connection.base_url' => 'Base URL',
                'authentication.username' => 'Username',
                'authentication.password' => 'Password'
            ];

            $missingConfigs = [];
            foreach ($requiredConfigs as $key => $description) {
                $value = data_get($config, $key);
                if (empty($value)) {
                    $missingConfigs[] = $description;
                }
            }

            if (empty($missingConfigs)) {
                $this->addHealthResult('Configuration', 'healthy', 'All required configuration values are set');
            } else {
                $this->addHealthResult('Configuration', 'warning', 'Missing configuration: ' . implode(', ', $missingConfigs));
            }

        } catch (Exception $e) {
            $this->addHealthResult('Configuration', 'unhealthy', 'Configuration check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check firewall API connectivity
     */
    protected function checkFirewallApiConnectivity(): void
    {
        $this->log('Checking firewall API connectivity...');

        try {
            $domain = config('hillstone.connection.domain');
            $baseUrl = config('hillstone.connection.base_url');

            if (empty($domain) || empty($baseUrl)) {
                $this->addHealthResult('API Connectivity', 'warning', 'Domain or base URL not configured');
                return;
            }

            // Simple connectivity test (without authentication)
            $url = rtrim($baseUrl, '/');
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'GET'
                ]
            ]);

            $result = @file_get_contents($url, false, $context);
            
            if ($result !== false || !empty($http_response_header)) {
                $this->addHealthResult('API Connectivity', 'healthy', 'Firewall API is reachable');
            } else {
                $this->addHealthResult('API Connectivity', 'warning', 'Firewall API may not be reachable');
            }

        } catch (Exception $e) {
            $this->addHealthResult('API Connectivity', 'unhealthy', 'API connectivity check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check authentication
     */
    protected function checkAuthentication(): void
    {
        $this->log('Checking authentication...');

        try {
            $authenticated = $this->client->authenticate();
            
            if ($authenticated) {
                $this->addHealthResult('Authentication', 'healthy', 'Authentication successful');
            } else {
                $this->addHealthResult('Authentication', 'unhealthy', 'Authentication failed');
            }

        } catch (CouldNotAuthenticateException $e) {
            $this->addHealthResult('Authentication', 'unhealthy', 'Authentication failed: ' . $e->getMessage());
        } catch (ApiRequestException $e) {
            $this->addHealthResult('Authentication', 'warning', 'API request failed: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->addHealthResult('Authentication', 'unhealthy', 'Authentication check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check database tables and data
     */
    protected function checkDatabaseTablesAndData(): void
    {
        $this->log('Checking database tables and data...');

        try {
            // Check if tables exist and have data
            $objectCount = HillstoneObject::count();
            $syncLogCount = SyncLog::count();

            $this->addHealthResult('Database Tables', 'healthy', "Tables exist with {$objectCount} objects and {$syncLogCount} sync logs");

            // Check for recent data
            $recentObjects = HillstoneObject::where('last_synced_at', '>', Carbon::now()->subDays(7))->count();
            
            if ($recentObjects > 0) {
                $this->addHealthResult('Data Freshness', 'healthy', "{$recentObjects} objects synced in the last 7 days");
            } elseif ($objectCount > 0) {
                $this->addHealthResult('Data Freshness', 'warning', 'No objects synced in the last 7 days');
            } else {
                $this->addHealthResult('Data Freshness', 'warning', 'No objects in database - run initial sync');
            }

        } catch (Exception $e) {
            $this->addHealthResult('Database Tables', 'unhealthy', 'Database table check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check cache system
     */
    protected function checkCacheSystem(): void
    {
        $this->log('Checking cache system...');

        try {
            $testKey = 'hillstone_health_check_' . time();
            $testValue = 'test_value';

            // Test cache write
            Cache::put($testKey, $testValue, 60);
            
            // Test cache read
            $cachedValue = Cache::get($testKey);
            
            if ($cachedValue === $testValue) {
                $this->addHealthResult('Cache System', 'healthy', 'Cache system is working');
                Cache::forget($testKey); // Cleanup
            } else {
                $this->addHealthResult('Cache System', 'warning', 'Cache system may not be working properly');
            }

        } catch (Exception $e) {
            $this->addHealthResult('Cache System', 'warning', 'Cache system check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check recent sync status
     */
    protected function checkRecentSyncStatus(): void
    {
        $this->log('Checking recent sync status...');

        try {
            $lastSync = SyncLog::orderBy('started_at', 'desc')->first();

            if (!$lastSync) {
                $this->addHealthResult('Recent Sync', 'warning', 'No sync operations found');
                return;
            }

            $daysSinceLastSync = Carbon::now()->diffInDays($lastSync->started_at);

            if ($lastSync->status === 'completed' && $daysSinceLastSync <= 1) {
                $this->addHealthResult('Recent Sync', 'healthy', "Last sync completed {$daysSinceLastSync} day(s) ago");
            } elseif ($lastSync->status === 'completed' && $daysSinceLastSync <= 7) {
                $this->addHealthResult('Recent Sync', 'warning', "Last sync completed {$daysSinceLastSync} day(s) ago");
            } elseif ($lastSync->status === 'failed') {
                $this->addHealthResult('Recent Sync', 'unhealthy', "Last sync failed: {$lastSync->error_message}");
            } else {
                $this->addHealthResult('Recent Sync', 'warning', "Last sync was {$daysSinceLastSync} day(s) ago");
            }

        } catch (Exception $e) {
            $this->addHealthResult('Recent Sync', 'warning', 'Sync status check failed: ' . $e->getMessage());
        }
    }

    /**
     * Check system resources
     */
    protected function checkSystemResources(): void
    {
        $this->log('Checking system resources...');

        try {
            // Check memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            if ($memoryLimit !== '-1') {
                $memoryLimitBytes = $this->convertToBytes($memoryLimit);
                $memoryUsagePercent = ($memoryUsage / $memoryLimitBytes) * 100;
                
                if ($memoryUsagePercent < 80) {
                    $this->addHealthResult('Memory Usage', 'healthy', sprintf('Memory usage: %.1f%% (%s)', $memoryUsagePercent, $this->formatBytes($memoryUsage)));
                } else {
                    $this->addHealthResult('Memory Usage', 'warning', sprintf('High memory usage: %.1f%% (%s)', $memoryUsagePercent, $this->formatBytes($memoryUsage)));
                }
            } else {
                $this->addHealthResult('Memory Usage', 'healthy', 'Memory usage: ' . $this->formatBytes($memoryUsage) . ' (no limit)');
            }

            // Check disk space (if possible)
            $diskFree = disk_free_space('.');
            $diskTotal = disk_total_space('.');
            
            if ($diskFree !== false && $diskTotal !== false) {
                $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
                
                if ($diskUsagePercent < 90) {
                    $this->addHealthResult('Disk Space', 'healthy', sprintf('Disk usage: %.1f%% (%.1f GB free)', $diskUsagePercent, $diskFree / 1024 / 1024 / 1024));
                } else {
                    $this->addHealthResult('Disk Space', 'warning', sprintf('Low disk space: %.1f%% (%.1f GB free)', $diskUsagePercent, $diskFree / 1024 / 1024 / 1024));
                }
            }

        } catch (Exception $e) {
            $this->addHealthResult('System Resources', 'warning', 'System resource check failed: ' . $e->getMessage());
        }
    }

    /**
     * Add health check result
     */
    protected function addHealthResult(string $check, string $status, string $message): void
    {
        $this->healthResults[] = [
            'check' => $check,
            'status' => $status, // healthy, warning, unhealthy
            'message' => $message,
            'timestamp' => Carbon::now()->toISOString()
        ];

        if ($this->verbose) {
            $icon = match($status) {
                'healthy' => '✓',
                'warning' => '⚠',
                'unhealthy' => '✗',
                default => '?'
            };
            $this->log("  {$icon} {$check}: {$message}");
        }
    }

    /**
     * Log message
     */
    protected function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Get health summary
     */
    public function getHealthSummary(): array
    {
        $total = count($this->healthResults);
        $healthy = count(array_filter($this->healthResults, fn($result) => $result['status'] === 'healthy'));
        $warning = count(array_filter($this->healthResults, fn($result) => $result['status'] === 'warning'));
        $unhealthy = count(array_filter($this->healthResults, fn($result) => $result['status'] === 'unhealthy'));

        $overallStatus = 'healthy';
        if ($unhealthy > 0) {
            $overallStatus = 'unhealthy';
        } elseif ($warning > 0) {
            $overallStatus = 'warning';
        }

        return [
            'overall_status' => $overallStatus,
            'total' => $total,
            'healthy' => $healthy,
            'warning' => $warning,
            'unhealthy' => $unhealthy,
            'timestamp' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Convert memory limit string to bytes
     */
    protected function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if system is healthy
     */
    public function isSystemHealthy(): bool
    {
        $summary = $this->getHealthSummary();
        return $summary['overall_status'] === 'healthy';
    }

    /**
     * Get unhealthy checks
     */
    public function getUnhealthyChecks(): array
    {
        return array_filter($this->healthResults, fn($result) => $result['status'] === 'unhealthy');
    }

    /**
     * Get warning checks
     */
    public function getWarningChecks(): array
    {
        return array_filter($this->healthResults, fn($result) => $result['status'] === 'warning');
    }
}