<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Services\HealthCheckService;

/**
 * Health Check Command
 * 
 * Runs comprehensive health checks to verify system status and connectivity.
 */
class HealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:health-check 
                            {--verbose : Show detailed health check output}
                            {--json : Output results in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run health checks to verify system status and connectivity';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $verbose = $this->option('verbose');
        $jsonOutput = $this->option('json');

        if (!$jsonOutput) {
            $this->info('Hillstone Firewall Sync - Health Check');
            $this->info('=====================================');
        }

        try {
            $client = app(HillstoneClientInterface::class);
            $healthService = new HealthCheckService($client, $verbose);

            if ($verbose && !$jsonOutput) {
                $this->line('');
            }

            // Run health checks
            $results = $healthService->runHealthChecks();
            $summary = $healthService->getHealthSummary();

            if ($jsonOutput) {
                $this->outputJson($results, $summary);
            } else {
                $this->displayResults($results, $summary, $verbose);
            }

            // Return appropriate exit code
            return $summary['unhealthy'] > 0 ? 1 : 0;

        } catch (\Exception $e) {
            if ($jsonOutput) {
                $this->line(json_encode([
                    'error' => 'Health check failed',
                    'message' => $e->getMessage()
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error('Health check failed: ' . $e->getMessage());
            }
            return 1;
        }
    }

    /**
     * Display health check results
     */
    protected function displayResults(array $results, array $summary, bool $verbose): void
    {
        $this->line('');
        $this->info('Health Check Summary:');
        $this->info('====================');

        // Overall status
        $overallStatus = $summary['overall_status'];
        $statusColor = match($overallStatus) {
            'healthy' => 'green',
            'warning' => 'yellow',
            'unhealthy' => 'red',
            default => 'white'
        };

        $this->line("Overall Status: <fg={$statusColor}>" . strtoupper($overallStatus) . "</>");
        $this->line("Total Checks: {$summary['total']}");
        $this->line("<fg=green>Healthy: {$summary['healthy']}</>");
        
        if ($summary['warning'] > 0) {
            $this->line("<fg=yellow>Warning: {$summary['warning']}</>");
        }
        
        if ($summary['unhealthy'] > 0) {
            $this->line("<fg=red>Unhealthy: {$summary['unhealthy']}</>");
        }

        if (!$verbose) {
            $this->line('');
            $this->info('Detailed Results:');
            $this->info('================');

            foreach ($results as $result) {
                $icon = match($result['status']) {
                    'healthy' => '<fg=green>✓</>',
                    'warning' => '<fg=yellow>⚠</>',
                    'unhealthy' => '<fg=red>✗</>',
                    default => '?'
                };
                $this->line("{$icon} {$result['check']}: {$result['message']}");
            }
        }

        $this->line('');

        // Show recommendations based on status
        if ($summary['unhealthy'] > 0) {
            $this->error('System health check failed. Critical issues need attention.');
            $this->showRecommendations($results, 'unhealthy');
        } elseif ($summary['warning'] > 0) {
            $this->warn('System health check completed with warnings.');
            $this->showRecommendations($results, 'warning');
        } else {
            $this->info('System health check passed! All systems are operational.');
        }
    }

    /**
     * Show recommendations for issues
     */
    protected function showRecommendations(array $results, string $statusFilter): void
    {
        $filteredResults = array_filter($results, fn($result) => $result['status'] === $statusFilter);
        
        if (empty($filteredResults)) {
            return;
        }

        $this->line('');
        $title = $statusFilter === 'unhealthy' ? 'Critical Issues:' : 'Warnings:';
        $this->info($title);
        $this->info(str_repeat('=', strlen($title)));

        $recommendations = [
            'Database Connection' => 'Check database configuration and ensure database server is running',
            'Configuration' => 'Review configuration file and set missing environment variables',
            'API Connectivity' => 'Verify firewall domain and base URL are correct and accessible',
            'Authentication' => 'Check username and password credentials for firewall access',
            'Database Tables' => 'Run migrations: php artisan migrate',
            'Cache System' => 'Check cache configuration and ensure cache driver is working',
            'Recent Sync' => 'Run a sync operation: php artisan hillstone:sync-all',
            'Memory Usage' => 'Consider increasing PHP memory limit or optimizing application',
            'Disk Space' => 'Free up disk space or expand storage capacity'
        ];

        foreach ($filteredResults as $result) {
            $check = $result['check'];
            $message = $result['message'];
            
            $this->line('');
            $this->line("<fg=yellow>Issue:</> {$check}");
            $this->line("<fg=gray>Details:</> {$message}");
            
            // Find matching recommendation
            foreach ($recommendations as $pattern => $recommendation) {
                if (str_contains($check, $pattern)) {
                    $this->line("<fg=cyan>Recommendation:</> {$recommendation}");
                    break;
                }
            }
        }
    }

    /**
     * Output results in JSON format
     */
    protected function outputJson(array $results, array $summary): void
    {
        $output = [
            'summary' => $summary,
            'results' => $results
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }
}