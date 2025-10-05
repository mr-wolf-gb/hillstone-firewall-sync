<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncSpecificObjectJob;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use Exception;

class SyncSpecificObjectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:sync-object 
                            {name : The name of the firewall object to sync}
                            {--queue : Run sync as background job}
                            {--verbose : Enable verbose output}
                            {--create : Create object if it doesn\'t exist locally}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize a specific Hillstone firewall address book object';

    /**
     * The sync service instance.
     *
     * @var SyncServiceInterface
     */
    protected $syncService;

    /**
     * Create a new command instance.
     *
     * @param SyncServiceInterface $syncService
     */
    public function __construct(SyncServiceInterface $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $objectName = $this->argument('name');
        
        try {
            $this->info("🔥 <fg=cyan>Hillstone Firewall Sync</fg=cyan> - Syncing object: <fg=yellow>{$objectName}</fg=yellow>");
            
            // Validate object name
            if (!$this->validateObjectName($objectName)) {
                return self::FAILURE;
            }

            // Check if object exists locally (unless creating)
            if (!$this->option('create') && !$this->objectExistsLocally($objectName)) {
                $this->warn("⚠️  Object '{$objectName}' not found locally.");
                
                if ($this->confirm('Would you like to create it from the firewall?')) {
                    $this->input->setOption('create', true);
                } else {
                    $this->error('❌ Sync cancelled. Use --create flag to sync new objects.');
                    return self::FAILURE;
                }
            }

            if ($this->option('queue')) {
                return $this->handleQueuedSync($objectName);
            }

            return $this->handleDirectSync($objectName);

        } catch (Exception $e) {
            $this->error("❌ Sync failed for object '{$objectName}': {$e->getMessage()}");
            
            if ($this->option('verbose')) {
                $this->error("Stack trace:");
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    /**
     * Handle direct synchronization of specific object.
     *
     * @param string $objectName
     * @return int
     */
    protected function handleDirectSync(string $objectName): int
    {
        $progressBar = $this->output->createProgressBar(4);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Initializing sync...');
        $progressBar->start();

        try {
            // Step 1: Authentication
            $progressBar->setMessage('Authenticating with Hillstone API...');
            $progressBar->advance();

            // Step 2: Fetch object data
            $progressBar->setMessage("Fetching object '{$objectName}' from firewall...");
            $progressBar->advance();

            // Step 3: Process and store
            $progressBar->setMessage('Processing and storing object data...');
            $result = $this->syncService->syncSpecific($objectName);
            $progressBar->advance();

            // Step 4: Complete
            $progressBar->setMessage('Sync completed successfully');
            $progressBar->advance();
            $progressBar->finish();
            $this->newLine(2);

            // Display results
            $this->displaySyncResults($objectName, $result);
            
            $this->info("✅ <fg=green>Object '{$objectName}' synchronized successfully!</fg=green>");
            return self::SUCCESS;

        } catch (Exception $e) {
            $progressBar->setMessage('Sync failed');
            $progressBar->finish();
            $this->newLine(2);
            
            throw $e;
        }
    }

    /**
     * Handle queued synchronization of specific object.
     *
     * @param string $objectName
     * @return int
     */
    protected function handleQueuedSync(string $objectName): int
    {
        $this->info("📋 Dispatching sync job for object '{$objectName}' to queue...");
        
        SyncSpecificObjectJob::dispatch($objectName);
        
        $this->info("✅ <fg=green>Sync job for '{$objectName}' dispatched successfully!</fg=green>");
        $this->info('💡 Use <fg=yellow>hillstone:sync-status</fg=yellow> to check progress.');
        
        return self::SUCCESS;
    }

    /**
     * Validate the object name parameter.
     *
     * @param string $objectName
     * @return bool
     */
    protected function validateObjectName(string $objectName): bool
    {
        // Basic validation
        if (empty(trim($objectName))) {
            $this->error('❌ Object name cannot be empty.');
            return false;
        }

        if (strlen($objectName) > 255) {
            $this->error('❌ Object name is too long (max 255 characters).');
            return false;
        }

        // Check for invalid characters (basic validation)
        if (preg_match('/[<>:"\/\\|?*]/', $objectName)) {
            $this->error('❌ Object name contains invalid characters.');
            return false;
        }

        if ($this->option('verbose')) {
            $this->info("✓ Object name '{$objectName}' is valid.");
        }

        return true;
    }

    /**
     * Check if object exists locally.
     *
     * @param string $objectName
     * @return bool
     */
    protected function objectExistsLocally(string $objectName): bool
    {
        $exists = HillstoneObject::where('name', $objectName)->exists();
        
        if ($this->option('verbose')) {
            $status = $exists ? 'found' : 'not found';
            $this->info("ℹ️  Object '{$objectName}' {$status} in local database.");
        }
        
        return $exists;
    }

    /**
     * Display sync results for the specific object.
     *
     * @param string $objectName
     * @param mixed $result
     */
    protected function displaySyncResults(string $objectName, $result): void
    {
        if (!$result) {
            return;
        }

        $this->info("📊 <fg=cyan>Sync Results for '{$objectName}':</fg=cyan>");
        
        $headers = ['Property', 'Value'];
        $rows = [
            ['Object Name', $objectName],
            ['Status', $result->status ?? 'Unknown'],
            ['Action Taken', $result->action ?? 'Unknown'],
            ['IP Addresses', $result->ipCount ?? 0],
        ];

        if (isset($result->lastSynced)) {
            $rows[] = ['Last Synced', $result->lastSynced];
        }

        $this->table($headers, $rows);

        if ($this->option('verbose') && isset($result->details)) {
            $this->info('🔍 <fg=cyan>Detailed Information:</fg=cyan>');
            foreach ($result->details as $detail) {
                $this->line("  • {$detail}");
            }
        }

        if (isset($result->warnings) && !empty($result->warnings)) {
            $this->warn('⚠️  Warnings:');
            foreach ($result->warnings as $warning) {
                $this->warn("  • {$warning}");
            }
        }
    }
}