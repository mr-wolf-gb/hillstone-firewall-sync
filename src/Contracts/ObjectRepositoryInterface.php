<?php

namespace MrWolfGb\HillstoneFirewallSync\Contracts;

interface ObjectRepositoryInterface
{
    /**
     * Create a new firewall object or update an existing one.
     * 
     * @param array $objectData The object data to create or update
     * @return mixed The created or updated object
     */
    public function createOrUpdate(array $objectData);

    /**
     * Find a firewall object by its name.
     * 
     * @param string $name The name of the object to find
     * @return mixed|null The found object or null if not found
     */
    public function findByName(string $name);

    /**
     * Delete stale objects that haven't been synced since the cutoff date.
     * 
     * @param mixed $cutoffDate Objects not synced since this date will be deleted
     * @return int The number of objects deleted
     */
    public function deleteStale($cutoffDate): int;
}