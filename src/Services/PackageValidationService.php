<?php

namespace MrWolfGb\HillstoneFirewallSync\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use Exception;

/**
 * Package Validation Service
 * 
 * Validates package installation, configuration, and component integration.
 */
class PackageValidationService
{
    protected array $validationResults = [];
    protected bool $verbose = false;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Run comprehensive package validation
     *
     * @return array
     */
    public function validatePackage(): array
    {
        $this->validationResults = [];

        $this->log('Starting Hillstone Firewall Sync Package Validation...');

        // Validation 1: Package Installation
        $this->validatePackageInstallation();

        // Validation 2: Configuration
        $this->validateConfiguration();

        // Validation 3: Database Setup
        $this->validateDatabaseSetup();

        // Validation 4: Service Registration
        $this->validateServiceRegistration();

        // Validation 5: Dependencies
        $this->validateDependencies();

        $this->log('Package validation completed.');

        return $this->validationResults;
    }

    /**
     * Validate package installation
     */
    protected function validatePackageInstallation(): void
    {
        $this->log('Validating package installation...');

        try {
            // Check if service provider is registered
            $providers = config('app.providers', []);
            $providerRegistered = in_array(
                'MrWolfGb\\HillstoneFirewallSync\\Providers\\HillstoneServiceProvider', 
                $providers
            ) || class_exists('MrWolfGb\\HillstoneFirewallSync\\Providers\\HillstoneServiceProvider');

            $this->addValidationResult('Service Provider Registration', 
                $providerRegistered, 
                $providerRegistered ? 'Service provider is registered' : 'Service provider not found in app.providers'
            );

            // Check if facade is available
            $facadeRegistered = class_exists('MrWolfGb\\HillstoneFirewallSync\\Facades\\HillstoneFirewall');
            $this->addValidationResult('Facade Registration', 
                $facadeRegistered, 
                $facadeRegistered ? 'Facade class is available' : 'Facade class not found'
            );

            // Check core classes exist
            $coreClasses = [
                'MrWolfGb\\HillstoneFirewallSync\\Services\\SyncService',
                'MrWolfGb\\HillstoneFirewallSync\\Http\\Client\\HillstoneClient',
                'MrWolfGb\\HillstoneFirewallSync\\Services\\ObjectRepository',
                'MrWolfGb\\HillstoneFirewallSync\\Services\\AuthenticationService'
            ];

            foreach ($coreClasses as $class) {
                $exists = class_exists($class);
                $this->addValidationResult("Core Class: " . class_basename($class), 
                    $exists, 
                    $exists ? 'Class is available' : 'Class not found'
                );
            }

        } catch (Exception $e) {
            $this->addValidationResult('Package Installation', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Validate configuration
     */
    protected function validateConfiguration(): void
    {
        $this->log('Validating configuration...');

        try {
            // Check if configuration is published
            $configExists = config('hillstone') !== null;
            $this->addValidationResult('Configuration Published', 
                $configExists, 
                $configExists ? 'Configuration is available' : 'Configuration not published - run php artisan vendor:publish --tag=hillstone-config'
            );

            if (!$configExists) {
                return;
            }

            $config = config('hillstone');

            // Validate required configuration sections
            $requiredSections = ['connection', 'authentication', 'sync', 'logging', 'events'];
            foreach ($requiredSections as $section) {
                $exists = array_key_exists($section, $config);
                $this->addValidationResult("Config Section: {$section}", 
                    $exists, 
                    $exists ? 'Section is configured' : "Missing {$section} configuration section"
                );
            }

            // Validate connection configuration
            $connection = $config['connection'] ?? [];
            $requiredConnectionKeys = ['domain', 'base_url', 'timeout', 'verify_ssl'];
            foreach ($requiredConnectionKeys as $key) {
                $exists = array_key_exists($key, $connection);
                $this->addValidationResult("Connection Config: {$key}", 
                    $exists, 
                    $exists ? 'Key is configured' : "Missing connection.{$key} configuration"
                );
            }

            // Validate authentication configuration
            $auth = $config['authentication'] ?? [];
            $requiredAuthKeys = ['username', 'password', 'token_cache_ttl'];
            foreach ($requiredAuthKeys as $key) {
                $exists = array_key_exists($key, $auth);
                $this->addValidationResult("Auth Config: {$key}", 
                    $exists, 
                    $exists ? 'Key is configured' : "Missing authentication.{$key} configuration"
                );
            }

            // Check for environment variables
            $envVars = [
                'HILLSTONE_DOMAIN' => $connection['domain'] ?? null,
                'HILLSTONE_BASE_URL' => $connection['base_url'] ?? null,
                'HILLSTONE_USERNAME' => $auth['username'] ?? null,
                'HILLSTONE_PASSWORD' => $auth['password'] ?? null
            ];

            foreach ($envVars as $var => $value) {
                $isSet = !empty($value);
                $this->addValidationResult("Environment Variable: {$var}", 
                    $isSet, 
                    $isSet ? 'Environment variable is set' : "Environment variable {$var} is not set"
                );
            }

        } catch (Exception $e) {
            $this->addValidationResult('Configuration Validation', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Validate database setup
     */
    protected function validateDatabaseSetup(): void
    {
        $this->log('Validating database setup...');

        try {
            // Check database connection
            DB::connection()->getPdo();
            $this->addValidationResult('Database Connection', true, 'Database connection is working');

            // Check if migrations are published
            $migrationPath = database_path('migrations');
            $migrationFiles = [
                'create_hillstone_objects_table.php',
                'create_hillstone_object_data_table.php',
                'create_hillstone_object_data_ips_table.php',
                'create_sync_logs_table.php'
            ];

            $migrationsPublished = true;
            foreach ($migrationFiles as $file) {
                $exists = file_exists($migrationPath . '/' . $file) || 
                         !empty(glob($migrationPath . '/*_' . $file));
                if (!$exists) {
                    $migrationsPublished = false;
                    break;
                }
            }

            $this->addValidationResult('Migrations Published', 
                $migrationsPublished, 
                $migrationsPublished ? 'Migration files are published' : 'Migration files not published - run php artisan vendor:publish --tag=hillstone-migrations'
            );

            // Check if tables exist
            $requiredTables = [
                'hillstone_objects',
                'hillstone_object_data', 
                'hillstone_object_data_ips',
                'sync_logs'
            ];

            foreach ($requiredTables as $table) {
                $exists = Schema::hasTable($table);
                $this->addValidationResult("Database Table: {$table}", 
                    $exists, 
                    $exists ? 'Table exists' : "Table {$table} not found - run php artisan migrate"
                );
            }

        } catch (Exception $e) {
            $this->addValidationResult('Database Setup', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Validate service registration
     */
    protected function validateServiceRegistration(): void
    {
        $this->log('Validating service registration...');

        try {
            // Test service resolution
            $services = [
                HillstoneClientInterface::class => 'HillstoneClient',
                ObjectRepositoryInterface::class => 'ObjectRepository',
                SyncServiceInterface::class => 'SyncService'
            ];

            foreach ($services as $interface => $name) {
                try {
                    $service = app($interface);
                    $this->addValidationResult("Service: {$name}", 
                        $service !== null, 
                        'Service is properly registered and resolvable'
                    );
                } catch (Exception $e) {
                    $this->addValidationResult("Service: {$name}", false, 
                        "Service resolution failed: " . $e->getMessage()
                    );
                }
            }

            // Test facade resolution
            try {
                $facade = app('hillstone-firewall');
                $this->addValidationResult('Facade Service Binding', 
                    $facade instanceof SyncServiceInterface, 
                    'Facade is properly bound to service'
                );
            } catch (Exception $e) {
                $this->addValidationResult('Facade Service Binding', false, 
                    'Facade binding failed: ' . $e->getMessage()
                );
            }

        } catch (Exception $e) {
            $this->addValidationResult('Service Registration', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Validate dependencies
     */
    protected function validateDependencies(): void
    {
        $this->log('Validating dependencies...');

        try {
            // Check required PHP extensions
            $requiredExtensions = ['curl', 'json', 'pdo'];
            foreach ($requiredExtensions as $extension) {
                $loaded = extension_loaded($extension);
                $this->addValidationResult("PHP Extension: {$extension}", 
                    $loaded, 
                    $loaded ? 'Extension is loaded' : "PHP extension {$extension} is required"
                );
            }

            // Check Laravel version compatibility
            $laravelVersion = app()->version();
            $isCompatible = version_compare($laravelVersion, '10.0', '>=');
            $this->addValidationResult('Laravel Version Compatibility', 
                $isCompatible, 
                $isCompatible ? "Laravel {$laravelVersion} is compatible" : "Laravel {$laravelVersion} may not be compatible (requires 10.0+)"
            );

            // Check required packages
            $requiredPackages = [
                'guzzlehttp/guzzle' => 'GuzzleHttp\\Client'
            ];

            foreach ($requiredPackages as $package => $class) {
                $exists = class_exists($class);
                $this->addValidationResult("Package: {$package}", 
                    $exists, 
                    $exists ? 'Package is installed' : "Package {$package} is required"
                );
            }

        } catch (Exception $e) {
            $this->addValidationResult('Dependencies', false, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Add validation result
     */
    protected function addValidationResult(string $check, bool $passed, string $message): void
    {
        $this->validationResults[] = [
            'check' => $check,
            'passed' => $passed,
            'message' => $message
        ];

        if ($this->verbose) {
            $status = $passed ? '✓' : '✗';
            $this->log("  {$status} {$check}: {$message}");
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
     * Get validation summary
     */
    public function getValidationSummary(): array
    {
        $total = count($this->validationResults);
        $passed = count(array_filter($this->validationResults, fn($result) => $result['passed']));
        $failed = $total - $passed;

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0
        ];
    }

    /**
     * Get failed validations
     */
    public function getFailedValidations(): array
    {
        return array_filter($this->validationResults, fn($result) => !$result['passed']);
    }

    /**
     * Check if package is ready for use
     */
    public function isPackageReady(): bool
    {
        $summary = $this->getValidationSummary();
        return $summary['failed'] === 0;
    }
}