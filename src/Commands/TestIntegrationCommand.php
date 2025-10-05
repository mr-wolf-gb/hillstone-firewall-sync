<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Services\IntegrationTestService;

/**
 * Test Integration Command
 * 
 * Runs comprehensive integration tests to verify all package components work together.
 */
class TestIntegrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:test-integration 
                            {--verbose : Show detailed test output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run integration tests to verify all package components work together';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Hillstone Firewall Sync - Integration Tests');
        $this->info('==========================================');

        $verbose = $this->option('verbose');
        $testService = new IntegrationTestService($verbose);

        if ($verbose) {
            $this->line('');
        }

        // Run integration tests
        $results = $testService->runIntegrationTests();
        $summary = $testService->getTestSummary();

        // Display results
        $this->displayResults($results, $summary, $verbose);

        // Return appropriate exit code
        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * Display test results
     */
    protected function displayResults(array $results, array $summary, bool $verbose): void
    {
        $this->line('');
        $this->info('Test Results Summary:');
        $this->info('====================');

        // Summary statistics
        $this->line("Total Tests: {$summary['total']}");
        $this->line("<fg=green>Passed: {$summary['passed']}</>");
        
        if ($summary['failed'] > 0) {
            $this->line("<fg=red>Failed: {$summary['failed']}</>");
        }
        
        $this->line("Success Rate: {$summary['success_rate']}%");

        if (!$verbose) {
            $this->line('');
            $this->info('Detailed Results:');
            $this->info('================');

            foreach ($results as $result) {
                $status = $result['passed'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line("{$status} {$result['test']}: {$result['message']}");
            }
        }

        $this->line('');

        if ($summary['failed'] > 0) {
            $this->error('Some integration tests failed. Please review the results above.');
            $this->line('');
            $this->info('Common issues and solutions:');
            $this->line('- Database tables not found: Run php artisan migrate');
            $this->line('- Configuration missing: Publish config with php artisan vendor:publish --tag=hillstone-config');
            $this->line('- Services not registered: Check service provider registration');
        } else {
            $this->info('All integration tests passed! The package is properly integrated.');
        }
    }
}