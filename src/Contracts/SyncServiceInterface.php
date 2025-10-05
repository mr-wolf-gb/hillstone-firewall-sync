<?php

namespace MrWolfGb\HillstoneFirewallSync\Contracts;

interface SyncServiceInterface
{
    /**
     * Synchronize all firewall objects from the API to the database.
     * 
     * @return mixed The sync operation log entry
     */
    public function syncAll();

    /**
     * Synchronize a specific firewall object by name.
     * 
     * @param string $objectName The name of the object to synchronize
     * @return mixed The sync operation log entry
     */
    public function syncSpecific(string $objectName);

    /**
     * Get the status of the last synchronization operation.
     * 
     * @return mixed|null The last sync log entry or null if no sync has been performed
     */
    public function getLastSyncStatus();
}