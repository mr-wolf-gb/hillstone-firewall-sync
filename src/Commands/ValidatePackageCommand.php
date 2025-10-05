<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Services\PackageValidationService;

/**
 * Validate Package Command
 * 
 * Validates package installation, configuration, and readiness for use.
 */
class ValidatePackageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:validate 
                            {--verbose : Show detailed validation output}
                            {--fix : Show suggestions for fixing issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate package installation, configuration, and readiness';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Hillstone Firewall Sync - Package Validation');
        $this->info('============================================');

        $verbose = $this->option('verbose');
        $showFix = $this->option('fix');
        
        $validationService = new PackageValidationService($verbose);

        if ($verbose) {
            $this->line('');
        }

        // Run package validation
        $results = $validationService->validatePackage();
        $summary = $validationService->getValidationSummary();

        // Display results
        $this->displayResults($results, $summary, $verbose, $showFix);

        // Return appropriate exit code
        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * Display validation results
     */
    protected function displayResults(array $results, array $summary, bool $verbose, bool $showFix): void
    {
        $this->line('');
        $this->info('Validation Results Summary:');
        $this->info('==========================');

        // Summary statistics
        $this->line("Total Checks: {$summary['total']}");
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
                $this->line("{$status} {$result['check']}: {$result['message']}");
            }
        }

        $this->line('');

        if ($summary['failed'] > 0) {
            $this->error('Package validation failed. Please review the results above.');
            
            if ($showFix) {
                $this->showFixSuggestions($results);
            } else {
                $this->line('');
                $this->info('Run with --fix option to see suggestions for fixing issues.');
            }
        } else {
            $this->info('Package validation passed! The package is ready for use.');
        }
    }

    /**
     * Show fix suggestions for failed validations
     */
    protected function showFixSuggestions(array $results): void
    {
        $failedResults = array_filter($results, fn($result) => !$result['passed']);
        
        if (empty($failedResults)) {
            return;
        }

        $this->line('');
        $this->info('Fix Suggestions:');
        $this->info('===============');

        $suggestions = [
            'Configuration Published' => [
                'php artisan vendor:publish --tag=hillstone-config',
                'This will publish the configuration file to config/hillstone.php'
            ],
            'Migrations Published' => [
                'php artisan vendor:publish --tag=hillstone-migrations',
                'This will publish the migration files to database/migrations/'
            ],
            'Database Table:' => [
                'php artisan migrate',
                'This will create the required database tables'
            ],
            'Environment Variable:' => [
                'Set the required environment variables in your .env file',
                'Example: HILLSTONE_DOMAIN=your-firewall-domain.com'
            ],
            'Service Provider Registration' => [
                'Add the service provider to config/app.php providers array',
                'Or ensure package auto-discovery is enabled in composer.json'
            ],
            'PHP Extension:' => [
                'Install the required PHP extension',
                'Contact your system administrator or hosting provider'
            ],
            'Package:' => [
                'Install the required package using Composer',
                'composer require [package-name]'
            ]
        ];

        foreach ($failedResults as $result) {
            $check = $result['check'];
            $suggestion = null;

            // Find matching suggestion
            foreach ($suggestions as $pattern => $fix) {
                if (str_contains($check, $pattern)) {
                    $suggestion = $fix;
                    break;
                }
            }

            if ($suggestion) {
                $this->line('');
                $this->line("<fg=yellow>Issue:</> {$check}");
                $this->line("<fg=cyan>Fix:</> {$suggestion[0]}");
                $this->line("<fg=gray>Info:</> {$suggestion[1]}");
            }
        }

        $this->line('');
        $this->info('After applying fixes, run this command again to verify the changes.');
    }
}