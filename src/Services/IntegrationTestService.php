<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Facades\HillstoneFirewall;
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncAllObjectsJob;
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncSpecificObjectJob;
use MrWolfGb\HillstoneFirewallSync\Jobs\CleanupOldObjectsJob;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectData;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use Exception;

/**
 * Integration Test Service
 * 
 * Provides comprehensive testing of all package components and their integration.
 */
class IntegrationTestService
{
    protected array $testResults = [];
    protected bool $verbose = false;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Run comprehensive integration tests
     *
     * @return array
     */
    public function runIntegrationTests(): array
    {
        $this->testResults = [];

        $this->log('Starting Hillstone Firewall Sync Integration Tests...');

        // Test 1: Dependency Injection
        $this->testDependencyInjection();

        // Test 2: Configuration Loading
        $this->testConfigurationLoading();

        // Test 3: Database Models
        $this->testDatabaseModels();

        // Test 4: Service Interfaces
        $this->testServiceInterfaces();

        // Test 5: Facade Access
        $this->testFacadeAccess();

        // Test 6: Artisan Commands
        $this->testArtisanCommands();

        // Test 7: Job Classes
        $this->testJobClasses();

        // Test 8: Event System
        $this->testEventSystem();

        $this->log('Integration tests completed.');

        return $this->testResults;
    }

    /**
     * Test dependency injection and service resolution
     */
    protected function testDependencyInjection(): void
    {
        $this->log('Testing dependency injection...');

        try {
            // Test core service resolution
            $hillstoneClient = app(HillstoneClientInterface::class);
            $this->addResult('HillstoneClientInterface Resolution', 
                $hillstoneClient instanceof HillstoneClientInterface, 
                'HillstoneClient should be resolvable from container'
            );

            $objectRepository = app(ObjectRepositoryInterface::class);
            $this->addResult('ObjectRepositoryInterface Resolution', 
                $objectRepository instanceof ObjectRepositoryInterface, 
                'ObjectRepository should be resolvable from container'
            );

            $syncService = app(SyncServiceInterface::class);
            $this->addResult('SyncServiceInterface Resolution', 
                $syncService instanceof SyncServiceInterface, 
                'SyncService should be resolvable from container'
            );

            $authService = app(AuthenticationService::class);
            $this->addResult('AuthenticationService Resolution', 
                $authService instanceof AuthenticationService, 
                'AuthenticationService should be resolvable from container'
            );

            // Test singleton behavior
            $syncService2 = app(SyncServiceInterface::class);
            $this->addResult('Singleton Behavior', 
                $syncService === $syncService2, 
                'Services should be registered as singletons'
            );

        } catch (Exception $e) {
            $this->addResult('Dependency Injection', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test configuration loading and validation
     */
    protected function testConfigurationLoading(): void
    {
        $this->log('Testing configuration loading...');

        try {
            // Test configuration exists
            $config = config('hillstone');
            $this->addResult('Configuration Loading', 
                is_array($config) && !empty($config), 
                'Hillstone configuration should be loaded'
            );

            // Test required configuration keys
            $requiredKeys = ['connection', 'authentication', 'sync', 'logging', 'events'];
            foreach ($requiredKeys as $key) {
                $this->addResult("Configuration Key: {$key}", 
                    array_key_exists($key, $config), 
                    "Configuration should contain {$key} section"
                );
            }

            // Test connection configuration
            $connection = $config['connection'] ?? [];
            $connectionKeys = ['domain', 'base_url', 'timeout', 'verify_ssl'];
            foreach ($connectionKeys as $key) {
                $this->addResult("Connection Config: {$key}", 
                    array_key_exists($key, $connection), 
                    "Connection configuration should contain {$key}"
                );
            }

        } catch (Exception $e) {
            $this->addResult('Configuration Loading', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test database models and relationships
     */
    protected function testDatabaseModels(): void
    {
        $this->log('Testing database models...');

        try {
            // Test model instantiation
            $hillstoneObject = new HillstoneObject();
            $this->addResult('HillstoneObject Model', 
                $hillstoneObject instanceof HillstoneObject, 
                'HillstoneObject model should be instantiable'
            );

            $hillstoneObjectData = new HillstoneObjectData();
            $this->addResult('HillstoneObjectData Model', 
                $hillstoneObjectData instanceof HillstoneObjectData, 
                'HillstoneObjectData model should be instantiable'
            );

            $syncLog = new SyncLog();
            $this->addResult('SyncLog Model', 
                $syncLog instanceof SyncLog, 
                'SyncLog model should be instantiable'
            );

            // Test model attributes
            $this->addResult('HillstoneObject Fillable', 
                in_array('name', $hillstoneObject->getFillable()), 
                'HillstoneObject should have fillable attributes'
            );

            $this->addResult('HillstoneObjectData Fillable', 
                in_array('name', $hillstoneObjectData->getFillable()), 
                'HillstoneObjectData should have fillable attributes'
            );

            // Test table existence (if migrations have been run)
            if (DB::getSchemaBuilder()->hasTable('hillstone_objects')) {
                $this->addResult('Database Tables', true, 'Database tables exist');
            } else {
                $this->addResult('Database Tables', false, 'Database tables not found - run migrations');
            }

        } catch (Exception $e) {
            $this->addResult('Database Models', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test service interfaces and implementations
     */
    protected function testServiceInterfaces(): void
    {
        $this->log('Testing service interfaces...');

        try {
            $syncService = app(SyncServiceInterface::class);
            
            // Test interface methods exist
            $this->addResult('SyncService Methods', 
                method_exists($syncService, 'syncAll') && 
                method_exists($syncService, 'syncSpecific') && 
                method_exists($syncService, 'getLastSyncStatus'), 
                'SyncService should implement all interface methods'
            );

            $hillstoneClient = app(HillstoneClientInterface::class);
            $this->addResult('HillstoneClient Methods', 
                method_exists($hillstoneClient, 'authenticate') && 
                method_exists($hillstoneClient, 'getAllAddressBookObjects') && 
                method_exists($hillstoneClient, 'getSpecificAddressBookObject'), 
                'HillstoneClient should implement all interface methods'
            );

            $objectRepository = app(ObjectRepositoryInterface::class);
            $this->addResult('ObjectRepository Methods', 
                method_exists($objectRepository, 'createOrUpdate') && 
                method_exists($objectRepository, 'findByName') && 
                method_exists($objectRepository, 'deleteStale'), 
                'ObjectRepository should implement all interface methods'
            );

        } catch (Exception $e) {
            $this->addResult('Service Interfaces', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test facade access
     */
    protected function testFacadeAccess(): void
    {
        $this->log('Testing facade access...');

        try {
            // Test facade resolution
            $facade = HillstoneFirewall::getFacadeRoot();
            $this->addResult('Facade Resolution', 
                $facade instanceof SyncServiceInterface, 
                'Facade should resolve to SyncService instance'
            );

            // Test facade methods are callable
            $this->addResult('Facade Methods', 
                method_exists($facade, 'syncAll') && 
                method_exists($facade, 'getLastSyncStatus'), 
                'Facade should provide access to service methods'
            );

        } catch (Exception $e) {
            $this->addResult('Facade Access', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test Artisan commands registration
     */
    protected function testArtisanCommands(): void
    {
        $this->log('Testing Artisan commands...');

        try {
            // Get all registered commands
            $commands = Artisan::all();
            
            $expectedCommands = [
                'hillstone:sync-all',
                'hillstone:sync-object',
                'hillstone:sync-status'
            ];

            foreach ($expectedCommands as $command) {
                $this->addResult("Command: {$command}", 
                    array_key_exists($command, $commands), 
                    "Command {$command} should be registered"
                );
            }

        } catch (Exception $e) {
            $this->addResult('Artisan Commands', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test job classes
     */
    protected function testJobClasses(): void
    {
        $this->log('Testing job classes...');

        try {
            // Test job instantiation
            $syncAllJob = new SyncAllObjectsJob();
            $this->addResult('SyncAllObjectsJob', 
                method_exists($syncAllJob, 'handle'), 
                'SyncAllObjectsJob should have handle method'
            );

            $syncSpecificJob = new SyncSpecificObjectJob('test-object');
            $this->addResult('SyncSpecificObjectJob', 
                method_exists($syncSpecificJob, 'handle'), 
                'SyncSpecificObjectJob should have handle method'
            );

            $cleanupJob = new CleanupOldObjectsJob();
            $this->addResult('CleanupOldObjectsJob', 
                method_exists($cleanupJob, 'handle'), 
                'CleanupOldObjectsJob should have handle method'
            );

            // Test job queueing (if queue is configured)
            if (config('queue.default') !== 'sync') {
                Queue::fake();
                
                SyncAllObjectsJob::dispatch();
                Queue::assertPushed(SyncAllObjectsJob::class);
                
                $this->addResult('Job Dispatching', true, 'Jobs can be dispatched to queue');
            }

        } catch (Exception $e) {
            $this->addResult('Job Classes', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Test event system
     */
    protected function testEventSystem(): void
    {
        $this->log('Testing event system...');

        try {
            // Test event classes exist
            $eventClasses = [
                'MrWolfGb\\HillstoneFirewallSync\\Events\\SyncStarted',
                'MrWolfGb\\HillstoneFirewallSync\\Events\\SyncCompleted',
                'MrWolfGb\\HillstoneFirewallSync\\Events\\SyncFailed',
                'MrWolfGb\\HillstoneFirewallSync\\Events\\ObjectSynced'
            ];

            foreach ($eventClasses as $eventClass) {
                $this->addResult("Event Class: " . class_basename($eventClass), 
                    class_exists($eventClass), 
                    "Event class {$eventClass} should exist"
                );
            }

        } catch (Exception $e) {
            $this->addResult('Event System', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Add test result
     */
    protected function addResult(string $test, bool $passed, string $message): void
    {
        $this->testResults[] = [
            'test' => $test,
            'passed' => $passed,
            'message' => $message
        ];

        if ($this->verbose) {
            $status = $passed ? '✓' : '✗';
            $this->log("  {$status} {$test}: {$message}");
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
     * Get test summary
     */
    public function getTestSummary(): array
    {
        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults, fn($result) => $result['passed']));
        $failed = $total - $passed;

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0
        ];
    }
}