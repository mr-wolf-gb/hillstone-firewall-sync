<?php

namespace MrWolfGb\HillstoneFirewallSync\Contracts;

interface HillstoneClientInterface
{
    /**
     * Authenticate with the Hillstone firewall API.
     * 
     * @return bool True if authentication successful, false otherwise
     */
    public function authenticate(): bool;

    /**
     * Check if the client is currently authenticated.
     * 
     * @return bool True if authenticated, false otherwise
     */
    public function isAuthenticated(): bool;

    /**
     * Retrieve all address book objects from the firewall.
     * 
     * @return \Illuminate\Support\Collection Collection of address book objects
     */
    public function getAllAddressBookObjects();

    /**
     * Retrieve a specific address book object by name.
     * 
     * @param string $name The name of the address book object
     * @return array The address book object data
     */
    public function getSpecificAddressBookObject(string $name): array;
}