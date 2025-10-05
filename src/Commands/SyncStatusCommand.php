<?php

namespace MrWolfGb\HillstoneFirewallSync\Commands;

use Illuminate\Console\Command;
use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectData;
use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObjectDataIP;
use Carbon\Carbon;

class SyncStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hillstone:sync-status 
                            {--detailed : Show detailed sync history}
                            {--recent=10 : Number of recent sync operations to show}
                            {--json : Output in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display Hillstone firewall synchronization status and statistics';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            if ($this->option('json')) {
                return $this->handleJsonOutput();
            }

            $this->displayHeader();
            $this->displayOverallStatus();
            $this->displayObjectStatistics();
            $this->displayRecentSyncHistory();
            
            if ($this->option('detailed')) {
                $this->displayDetailedHistory();
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to retrieve sync status: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Display the command header.
     */
    protected function displayHeader(): void
    {
        $this->info('🔥 <fg=cyan>Hillstone Firewall Sync Status</fg=cyan>');
        $this->info('Generated at: ' . now()->format('Y-m-d H:i:s T'));
        $this->newLine();
    }

    /**
     * Display overall synchronization status.
     */
    protected function displayOverallStatus(): void
    {
        $lastSync = SyncLog::latest('started_at')->first();
        $lastSuccessfulSync = SyncLog::where('status', 'completed')
            ->latest('completed_at')
            ->first();

        $this->info('📊 <fg=cyan>Overall Status:</fg=cyan>');
        
        $headers = ['Metric', 'Value', 'Status'];
        $rows = [];

        if ($lastSync) {
            $statusColor = $this->getStatusColor($lastSync->status);
            $rows[] = [
                'Last Sync Operation',
                $lastSync->started_at->format('Y-m-d H:i:s'),
                "<fg={$statusColor}>" . ucfirst($lastSync->status) . "</fg={$statusColor}>"
            ];
            
            if ($lastSync->status === 'completed') {
                $rows[] = [
                    'Duration',
                    $this->formatDuration($lastSync->started_at, $lastSync->completed_at),
                    '<fg=green>✓</fg=green>'
                ];
            }
        } else {
            $rows[] = ['Last Sync Operation', 'Never', '<fg=yellow>-</fg=yellow>'];
        }

        if ($lastSuccessfulSync && $lastSuccessfulSync->id !== $lastSync?->id) {
            $rows[] = [
                'Last Successful Sync',
                $lastSuccessfulSync->completed_at->format('Y-m-d H:i:s'),
                '<fg=green>✓</fg=green>'
            ];
        }

        // Check if sync is overdue (more than 24 hours)
        $isOverdue = !$lastSuccessfulSync || $lastSuccessfulSync->completed_at->lt(now()->subDay());
        $overdueStatus = $isOverdue ? '<fg=red>Overdue</fg=red>' : '<fg=green>Current</fg=green>';
        $rows[] = ['Sync Status', $isOverdue ? 'Sync needed' : 'Up to date', $overdueStatus];

        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Display object statistics.
     */
    protected function displayObjectStatistics(): void
    {
        $this->info('📈 <fg=cyan>Object Statistics:</fg=cyan>');
        
        $objectCount = HillstoneObject::count();
        $objectDataCount = HillstoneObjectData::count();
        $ipCount = HillstoneObjectDataIP::count();
        
        $recentObjects = HillstoneObject::where('last_synced_at', '>', now()->subDay())->count();
        $staleObjects = HillstoneObject::where('last_synced_at', '<', now()->subWeek())->count();
        
        $headers = ['Category', 'Count', 'Details'];
        $rows = [
            ['Hillstone Objects', number_format($objectCount), 'Total address book objects'],
            ['Object Data Records', number_format($objectDataCount), 'Detailed object information'],
            ['IP Address Records', number_format($ipCount), 'Individual IP addresses'],
            ['Recently Synced', number_format($recentObjects), 'Synced in last 24 hours'],
            ['Stale Objects', number_format($staleObjects), 'Not synced in last week'],
        ];

        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Display recent sync history.
     */
    protected function displayRecentSyncHistory(): void
    {
        $limit = (int) $this->option('recent');
        $recentSyncs = SyncLog::latest('started_at')->limit($limit)->get();

        if ($recentSyncs->isEmpty()) {
            $this->warn('⚠️  No sync operations found in history.');
            return;
        }

        $this->info("📋 <fg=cyan>Recent Sync Operations (Last {$limit}):</fg=cyan>");
        
        $headers = ['Date/Time', 'Type', 'Status', 'Objects', 'Duration', 'Errors'];
        $rows = [];

        foreach ($recentSyncs as $sync) {
            $statusColor = $this->getStatusColor($sync->status);
            $duration = $sync->completed_at 
                ? $this->formatDuration($sync->started_at, $sync->completed_at)
                : 'In Progress';
            
            $errorInfo = $sync->error_message ? '⚠️' : '-';
            
            $rows[] = [
                $sync->started_at->format('m-d H:i'),
                ucfirst(str_replace('_', ' ', $sync->operation_type)),
                "<fg={$statusColor}>" . ucfirst($sync->status) . "</fg={$statusColor}>",
                number_format($sync->objects_processed),
                $duration,
                $errorInfo
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Display detailed sync history.
     */
    protected function displayDetailedHistory(): void
    {
        $this->info('🔍 <fg=cyan>Detailed Sync History:</fg=cyan>');
        
        $detailedSyncs = SyncLog::with([])
            ->latest('started_at')
            ->limit(5)
            ->get();

        foreach ($detailedSyncs as $sync) {
            $this->displaySyncDetails($sync);
        }
    }

    /**
     * Display details for a specific sync operation.
     *
     * @param SyncLog $sync
     */
    protected function displaySyncDetails(SyncLog $sync): void
    {
        $statusColor = $this->getStatusColor($sync->status);
        
        $this->info("🔸 Sync ID: {$sync->id} - <fg={$statusColor}>" . ucfirst($sync->status) . "</fg={$statusColor}>");
        $this->line("   Started: {$sync->started_at->format('Y-m-d H:i:s')}");
        
        if ($sync->completed_at) {
            $this->line("   Completed: {$sync->completed_at->format('Y-m-d H:i:s')}");
            $this->line("   Duration: " . $this->formatDuration($sync->started_at, $sync->completed_at));
        }
        
        $this->line("   Type: " . ucfirst(str_replace('_', ' ', $sync->operation_type)));
        $this->line("   Objects Processed: " . number_format($sync->objects_processed));
        
        if ($sync->objects_created > 0) {
            $this->line("   Objects Created: " . number_format($sync->objects_created));
        }
        
        if ($sync->objects_updated > 0) {
            $this->line("   Objects Updated: " . number_format($sync->objects_updated));
        }
        
        if ($sync->objects_deleted > 0) {
            $this->line("   Objects Deleted: " . number_format($sync->objects_deleted));
        }
        
        if ($sync->error_message) {
            $this->error("   Error: {$sync->error_message}");
        }
        
        $this->newLine();
    }

    /**
     * Handle JSON output format.
     *
     * @return int
     */
    protected function handleJsonOutput(): int
    {
        $lastSync = SyncLog::latest('started_at')->first();
        $lastSuccessfulSync = SyncLog::where('status', 'completed')
            ->latest('completed_at')
            ->first();

        $data = [
            'timestamp' => now()->toISOString(),
            'overall_status' => [
                'last_sync' => $lastSync ? [
                    'id' => $lastSync->id,
                    'started_at' => $lastSync->started_at->toISOString(),
                    'completed_at' => $lastSync->completed_at?->toISOString(),
                    'status' => $lastSync->status,
                    'operation_type' => $lastSync->operation_type,
                ] : null,
                'last_successful_sync' => $lastSuccessfulSync ? [
                    'id' => $lastSuccessfulSync->id,
                    'completed_at' => $lastSuccessfulSync->completed_at->toISOString(),
                ] : null,
                'is_overdue' => !$lastSuccessfulSync || $lastSuccessfulSync->completed_at->lt(now()->subDay()),
            ],
            'statistics' => [
                'objects' => HillstoneObject::count(),
                'object_data' => HillstoneObjectData::count(),
                'ip_addresses' => HillstoneObjectDataIP::count(),
                'recent_syncs' => HillstoneObject::where('last_synced_at', '>', now()->subDay())->count(),
                'stale_objects' => HillstoneObject::where('last_synced_at', '<', now()->subWeek())->count(),
            ],
            'recent_syncs' => SyncLog::latest('started_at')
                ->limit((int) $this->option('recent'))
                ->get()
                ->map(function ($sync) {
                    return [
                        'id' => $sync->id,
                        'started_at' => $sync->started_at->toISOString(),
                        'completed_at' => $sync->completed_at?->toISOString(),
                        'status' => $sync->status,
                        'operation_type' => $sync->operation_type,
                        'objects_processed' => $sync->objects_processed,
                        'objects_created' => $sync->objects_created,
                        'objects_updated' => $sync->objects_updated,
                        'objects_deleted' => $sync->objects_deleted,
                        'has_error' => !empty($sync->error_message),
                    ];
                }),
        ];

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    /**
     * Get color for sync status.
     *
     * @param string $status
     * @return string
     */
    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'green',
            'failed' => 'red',
            'started' => 'yellow',
            default => 'white',
        };
    }

    /**
     * Format duration between two timestamps.
     *
     * @param Carbon $start
     * @param Carbon|null $end
     * @return string
     */
    protected function formatDuration(Carbon $start, ?Carbon $end): string
    {
        if (!$end) {
            return 'In Progress';
        }

        $diff = $start->diffInSeconds($end);
        
        if ($diff < 60) {
            return "{$diff}s";
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            $seconds = $diff % 60;
            return "{$minutes}m {$seconds}s";
        } else {
            $hours = floor($diff / 3600);
            $minutes = floor(($diff % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
    }
}