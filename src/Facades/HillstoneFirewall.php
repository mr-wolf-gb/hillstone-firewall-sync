<?php

namespace MrWolfGb\HillstoneFirewallSync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * HillstoneFirewall Facade
 * 
 * Provides easy access to Hillstone firewall synchronization operations.
 * 
 * @method static \MrWolfGb\HillstoneFirewallSync\Models\SyncLog syncAll()
 * @method static \MrWolfGb\HillstoneFirewallSync\Models\SyncLog syncSpecific(string $objectName)
 * @method static \MrWolfGb\HillstoneFirewallSync\Models\SyncLog|null getLastSyncStatus()
 * @method static array getSyncStatistics(int $days = 7)
 * @method static bool isSyncRunning()
 * 
 * @see \MrWolfGb\HillstoneFirewallSync\Services\SyncService
 */
class HillstoneFirewall extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'hillstone-firewall';
    }
}