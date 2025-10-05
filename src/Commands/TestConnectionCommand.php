<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Contracts\HillstoneClientInterface;
use MrWolfGb\HillstoneFirewallSync\Services\ConnectionTestService;

/**
 * Test Connection Command
 * 
 * Tests connectivity and authentication with the Hillstone firewall.
 */
class TestConnectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:test-connection 
                            {--connectivity : Test only basic connectivity}
                            {--auth : Test only authentication}
                            {--api : Test only API access}
                            {--json : Output results in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connectivity and authentication with Hillstone firewall';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $jsonOutput = $this->option('json');

        if (!$jsonOutput) {
            $this->info('Hillstone Firewall Sync - Connection Test');
            $this->info('=========================================');
        }

        try {
            $client = app(HillstoneClientInterface::class);
            $connectionService = new ConnectionTestService($client);

            // Determine which tests to run
            $testConnectivity = $this->option('connectivity') || (!$this->option('auth') && !$this->option('api'));
            $testAuth = $this->option('auth') || (!$this->option('connectivity') && !$this->option('api'));
            $testApi = $this->option('api') || (!$this->option('connectivity') && !$this->option('auth'));

            $results = [];

            if ($testConnectivity) {
                if (!$jsonOutput) $this->line('');
                if (!$jsonOutput) $this->info('Testing connectivity...');
                $results['connectivity'] = $connectionService->testConnectivity();
                if (!$jsonOutput) $this->displayTestResult('Connectivity', $results['connectivity']);
            }

            if ($testAuth && (!isset($results['connectivity']) || $results['connectivity']['success'])) {
                if (!$jsonOutput) $this->line('');
                if (!$jsonOutput) $this->info('Testing authentication...');
                $results['authentication'] = $connectionService->testAuthentication();
                if (!$jsonOutput) $this->displayTestResult('Authentication', $results['authentication']);
            }

            if ($testApi && (!isset($results['authentication']) || $results['authentication']['success'])) {
                if (!$jsonOutput) $this->line('');
                if (!$jsonOutput) $this->info('Testing API access...');
                $results['api_access'] = $connectionService->testApiAccess();
                if (!$jsonOutput) $this->displayTestResult('API Access', $results['api_access']);
            }

            // Determine overall success
            $overallSuccess = true;
            foreach ($results as $result) {
                if (!$result['success']) {
                    $overallSuccess = false;
                    break;
                }
            }

            if ($jsonOutput) {
                $this->line(json_encode([
                    'overall_success' => $overallSuccess,
                    'tests' => $results
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displaySummary($results, $overallSuccess);
            }

            return $overallSuccess ? 0 : 1;

        } catch (\Exception $e) {
            if ($jsonOutput) {
                $this->line(json_encode([
                    'error' => 'Connection test failed',
                    'message' => $e->getMessage()
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error('Connection test failed: ' . $e->getMessage());
            }
            return 1;
        }
    }

    /**
     * Display test result
     */
    protected function displayTestResult(string $testName, array $result): void
    {
        $status = $result['success'] ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
        $this->line("{$testName}: {$status}");
        $this->line("Message: {$result['message']}");
        
        if (!empty($result['details'])) {
            $this->line('Details:');
            foreach ($result['details'] as $detail) {
                $this->line("  - {$detail}");
            }
        }
    }

    /**
     * Display summary
     */
    protected function displaySummary(array $results, bool $overallSuccess): void
    {
        $this->line('');
        $this->info('Connection Test Summary:');
        $this->info('=======================');

        $totalTests = count($results);
        $passedTests = count(array_filter($results, fn($result) => $result['success']));
        $failedTests = $totalTests - $passedTests;

        $this->line("Total Tests: {$totalTests}");
        $this->line("<fg=green>Passed: {$passedTests}</>");
        
        if ($failedTests > 0) {
            $this->line("<fg=red>Failed: {$failedTests}</>");
        }

        $this->line('');

        if ($overallSuccess) {
            $this->info('All connection tests passed! The firewall is accessible and configured correctly.');
        } else {
            $this->error('Some connection tests failed. Please review the results above.');
            $this->line('');
            $this->info('Common solutions:');
            $this->line('- Verify firewall domain and base URL are correct');
            $this->line('- Check username and password credentials');
            $this->line('- Ensure firewall is accessible from this network');
            $this->line('- Verify SSL/TLS configuration if using HTTPS');
        }
    }
}