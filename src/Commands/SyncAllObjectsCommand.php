<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Contracts\SyncServiceInterface;
use MrWolfGb\HillstoneFirewallSync\Jobs\SyncAllObjectsJob;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use Exception;

class SyncAllObjectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:sync-all 
                            {--queue : Run sync as background job}
                            {--verbose : Enable verbose output}
                            {--force : Force sync even if recent sync exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize all Hillstone firewall address book objects';

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
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        $this->logCommandEvent('command_started', [
            'command' => $this->signature,
            'options' => [
                'queue' => $this->option('queue'),
                'verbose' => $this->option('verbose'),
                'force' => $this->option('force'),
            ],
            'memory_usage_start' => $startMemory,
        ]);

        try {
            $this->info('🔥 <fg=cyan>Hillstone Firewall Sync</fg=cyan> - Starting full synchronization...');
            
            // Check for recent sync unless forced
            if (!$this->option('force') && $this->hasRecentSync()) {
                $this->warn('⚠️  Recent sync found. Use --force to override or wait before syncing again.');
                
                $this->logCommandEvent('command_skipped_recent_sync', [
                    'reason' => 'recent_sync_exists',
                    'force_option' => false,
                    'duration_seconds' => round(microtime(true) - $startTime, 3),
                ], 'warning');
                
                return self::FAILURE;
            }

            if ($this->option('queue')) {
                return $this->handleQueuedSync($startTime, $startMemory);
            }

            return $this->handleDirectSync($startTime, $startMemory);

        } catch (Exception $e) {
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->error("❌ Sync failed: {$e->getMessage()}");
            
            if ($this->option('verbose')) {
                $this->error("Stack trace:");
                $this->error($e->getTraceAsString());
            }
            
            $this->logCommandEvent('command_failed', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
                'verbose_output' => $this->option('verbose'),
            ], 'error');
            
            return self::FAILURE;
        }
    }

    /**
     * Handle direct synchronization.
     *
     * @param float $startTime
     * @param int $startMemory
     * @return int
     */
    protected function handleDirectSync(float $startTime, int $startMemory): int
    {
        $syncStartTime = microtime(true);
        
        $this->logCommandEvent('direct_sync_started', [
            'sync_start_time' => $syncStartTime,
        ]);

        $progressBar = $this->output->createProgressBar();
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Initializing sync...');
        $progressBar->start();

        try {
            // Start sync operation
            $progressBar->setMessage('Authenticating with Hillstone API...');
            $progressBar->advance();

            $result = $this->syncService->syncAll();
            
            $progressBar->setMessage('Sync completed successfully');
            $progressBar->finish();
            $this->newLine(2);

            // Display results
            $this->displaySyncResults($result);
            
            $syncDuration = microtime(true) - $syncStartTime;
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            $this->info('✅ <fg=green>Synchronization completed successfully!</fg=green>');
            
            $this->logCommandEvent('direct_sync_completed', [
                'sync_log_id' => $result->id ?? null,
                'objects_processed' => $result->objects_processed ?? 0,
                'objects_created' => $result->objects_created ?? 0,
                'objects_updated' => $result->objects_updated ?? 0,
                'objects_deleted' => $result->objects_deleted ?? 0,
                'sync_duration_seconds' => round($syncDuration, 3),
                'total_duration_seconds' => round($totalDuration, 3),
                'throughput_objects_per_second' => ($result->objects_processed ?? 0) > 0 ? round(($result->objects_processed ?? 0) / $syncDuration, 2) : 0,
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_peak' => $peakMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
            ]);
            
            return self::SUCCESS;

        } catch (Exception $e) {
            $syncDuration = microtime(true) - $syncStartTime;
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $progressBar->setMessage('Sync failed');
            $progressBar->finish();
            $this->newLine(2);
            
            $this->logCommandEvent('direct_sync_failed', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'sync_duration_before_failure' => round($syncDuration, 3),
                'total_duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
            ], 'error');
            
            throw $e;
        }
    }

    /**
     * Handle queued synchronization.
     *
     * @param float $startTime
     * @param int $startMemory
     * @return int
     */
    protected function handleQueuedSync(float $startTime, int $startMemory): int
    {
        $dispatchStartTime = microtime(true);
        
        $this->info('📋 Dispatching sync job to queue...');
        
        try {
            SyncAllObjectsJob::dispatch();
            
            $dispatchDuration = microtime(true) - $dispatchStartTime;
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->info('✅ <fg=green>Sync job dispatched successfully!</fg=green>');
            $this->info('💡 Use <fg=yellow>hillstone:sync-status</fg=yellow> to check progress.');
            
            $this->logCommandEvent('queued_sync_dispatched', [
                'job_class' => SyncAllObjectsJob::class,
                'dispatch_duration_seconds' => round($dispatchDuration, 4),
                'total_duration_seconds' => round($totalDuration, 3),
                'memory_usage_start' => $startMemory,
                'memory_usage_end' => $endMemory,
                'memory_usage_delta' => $endMemory - $startMemory,
            ]);
            
            return self::SUCCESS;
            
        } catch (Exception $e) {
            $dispatchDuration = microtime(true) - $dispatchStartTime;
            $totalDuration = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            
            $this->logCommandEvent('queued_sync_dispatch_failed', [
                'job_class' => SyncAllObjectsJob::class,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'dispatch_duration_before_failure' => round($dispatchDuration, 4),
                'total_duration_before_failure' => round($totalDuration, 3),
                'memory_usage_at_failure' => $endMemory,
            ], 'error');
            
            throw $e;
        }
    }

    /**
     * Display sync results in a formatted table.
     *
     * @param mixed $result
     */
    protected function displaySyncResults($result): void
    {
        if (!$result) {
            return;
        }

        $this->info('📊 <fg=cyan>Sync Results:</fg=cyan>');
        
        $headers = ['Metric', 'Count'];
        $rows = [
            ['Objects Processed', $result->objectsProcessed ?? 0],
            ['Objects Created', $result->objectsCreated ?? 0],
            ['Objects Updated', $result->objectsUpdated ?? 0],
            ['Objects Deleted', $result->objectsDeleted ?? 0],
        ];

        $this->table($headers, $rows);

        if ($this->option('verbose') && isset($result->details)) {
            $this->info('🔍 <fg=cyan>Detailed Information:</fg=cyan>');
            foreach ($result->details as $detail) {
                $this->line("  • {$detail}");
            }
        }
    }

    /**
     * Check if there's a recent successful sync.
     *
     * @return bool
     */
    protected function hasRecentSync(): bool
    {
        $recentSync = SyncLog::where('operation_type', 'full_sync')
            ->where('status', 'completed')
            ->where('started_at', '>', now()->subHour())
            ->first();

        if ($recentSync && $this->option('verbose')) {
            $this->info("ℹ️  Last sync completed at: {$recentSync->completed_at}");
        }

        return $recentSync !== null;
    }

    /**
     * Log command events with structured context.
     * 
     * @param string $event
     * @param array $context
     * @param string $level
     */
    private function logCommandEvent(string $event, array $context = [], string $level = 'info'): void
    {
        $config = config('hillstone.logging', []);
        
        if (!($config['log_sync_operations'] ?? true)) {
            return;
        }

        $channel = $config['channel'] ?? 'default';
        
        $logContext = array_merge([
            'service' => 'SyncAllObjectsCommand',
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'command_class' => self::class,
            'command_signature' => $this->signature,
        ], $context);

        // Add performance metrics if enabled
        if ($config['log_performance_metrics'] ?? false) {
            $logContext['memory_usage'] = memory_get_usage(true);
            $logContext['peak_memory'] = memory_get_peak_usage(true);
        }

        switch ($level) {
            case 'debug':
                Log::channel($channel)->debug("SyncAllObjectsCommand: {$event}", $logContext);
                break;
            case 'info':
                Log::channel($channel)->info("SyncAllObjectsCommand: {$event}", $logContext);
                break;
            case 'warning':
                Log::channel($channel)->warning("SyncAllObjectsCommand: {$event}", $logContext);
                break;
            case 'error':
                Log::channel($channel)->error("SyncAllObjectsCommand: {$event}", $logContext);
                break;
            default:
                Log::channel($channel)->info("SyncAllObjectsCommand: {$event}", $logContext);
        }
    }
}