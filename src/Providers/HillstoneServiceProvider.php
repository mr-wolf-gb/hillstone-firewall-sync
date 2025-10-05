<?php

namespace MrWolfGb\HillstoneFirewallSync\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use MrWolfGb\HillstoneFirewallSync\Commands\SyncAllObjectsCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\SyncSpecificObjectCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\SyncStatusCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\TestIntegrationCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\ValidatePackageCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\HealthCheckCommand;
use MrWolfGb\HillstoneFirewallSync\Commands\TestConnectionCommand;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\ObjectRepositoryInterface;
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Http\Client\HillstoneClient;
use MrWolfGb\HillstoneFirewallSync\Services\ObjectRepository;
use MrWolfGb\HillstoneFirewallSync\Services\SyncService;
use MrWolfGb\HillstoneFirewallSync\Services\AuthenticationService;

class HillstoneServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/hillstone.php',
            'hillstone'
        );

        // Register core services and bind interfaces to implementations
        $this->registerCoreServices();
        
        // Register facade accessor
        $this->registerFacadeAccessor();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Register Artisan commands
        $this->registerCommands();
        
        // Publish package assets
        $this->registerPublishing();
        
        // Load package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        
        // Register event listeners if configured
        $this->registerEventListeners();
        
        // Validate configuration in non-production environments
        $this->validateConfiguration();
    }

    /**
     * Register core services and bind interfaces to implementations.
     *
     * @return void
     */
    protected function registerCoreServices(): void
    {
        // Register Authentication Service
        $this->app->singleton(AuthenticationService::class, function ($app) {
            return new AuthenticationService(
                $app['config']['hillstone'],
                $app['cache.store'],
                $app['log']
            );
        });

        // Register and bind Hillstone Client Interface
        $this->app->singleton(HillstoneClientInterface::class, function ($app) {
            return new HillstoneClient(
                $app[AuthenticationService::class],
                $app['config']['hillstone'],
                $app['log']
            );
        });

        // Register and bind Object Repository Interface
        $this->app->singleton(ObjectRepositoryInterface::class, function ($app) {
            return new ObjectRepository(
                $app['config']['hillstone'],
                $app['log']
            );
        });

        // Register and bind Sync Service Interface
        $this->app->singleton(SyncServiceInterface::class, function ($app) {
            return new SyncService(
                $app[HillstoneClientInterface::class],
                $app[ObjectRepositoryInterface::class],
                $app['config']['hillstone'],
                $app['events'],
                $app['log']
            );
        });

        // Register main service alias for facade
        $this->app->alias(SyncServiceInterface::class, 'hillstone-firewall');
    }

    /**
     * Register facade accessor.
     *
     * @return void
     */
    protected function registerFacadeAccessor(): void
    {
        $this->app->singleton('hillstone-firewall', function ($app) {
            return $app[SyncServiceInterface::class];
        });
    }

    /**
     * Register Artisan commands.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAllObjectsCommand::class,
                SyncSpecificObjectCommand::class,
                SyncStatusCommand::class,
                TestIntegrationCommand::class,
                ValidatePackageCommand::class,
                HealthCheckCommand::class,
                TestConnectionCommand::class,
            ]);
        }
    }

    /**
     * Register package publishing.
     *
     * @return void
     */
    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish configuration file
            $this->publishes([
                __DIR__ . '/../../config/hillstone.php' => config_path('hillstone.php'),
            ], 'hillstone-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'hillstone-migrations');

            // Publish all assets
            $this->publishes([
                __DIR__ . '/../../config/hillstone.php' => config_path('hillstone.php'),
                __DIR__ . '/../../database/migrations' => database_path('migrations'),
            ], 'hillstone');
        }
    }

    /**
     * Register event listeners if configured.
     *
     * @return void
     */
    protected function registerEventListeners(): void
    {
        if (!config('hillstone.events.enabled', true)) {
            return;
        }

        // Event listeners can be registered here if needed
        // For now, events are dispatched directly from services
        // Custom listeners can be registered by the application
    }

    /**
     * Validate configuration in non-production environments.
     *
     * @return void
     */
    protected function validateConfiguration(): void
    {
        // Only validate in non-production environments to avoid performance impact
        if ($this->app->environment('production')) {
            return;
        }

        $config = config('hillstone');
        
        if (empty($config)) {
            return; // Configuration not published yet
        }

        // Log warnings for missing critical configuration
        $criticalConfigs = [
            'connection.domain' => 'HILLSTONE_DOMAIN',
            'connection.base_url' => 'HILLSTONE_BASE_URL',
            'authentication.username' => 'HILLSTONE_USERNAME',
            'authentication.password' => 'HILLSTONE_PASSWORD'
        ];

        foreach ($criticalConfigs as $configKey => $envVar) {
            $value = data_get($config, $configKey);
            if (empty($value)) {
                \Log::warning("Hillstone Firewall Sync: Missing configuration for {$configKey}. Set {$envVar} environment variable.");
            }
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'hillstone-firewall',
            HillstoneClientInterface::class,
            ObjectRepositoryInterface::class,
            SyncServiceInterface::class,
            AuthenticationService::class,
            SyncAllObjectsCommand::class,
            SyncSpecificObjectCommand::class,
            SyncStatusCommand::class,
            TestIntegrationCommand::class,
            ValidatePackageCommand::class,
            HealthCheckCommand::class,
            TestConnectionCommand::class,
        ];
    }
}