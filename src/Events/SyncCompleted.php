<?php

namespace MrWolfGb\HillstoneFirewallSync\Events;

use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCompleted
{
    use Dispatchable, SerializesModels;

    public SyncLog $syncLog;
    public array $results;

    /**
     * Create a new event instance.
     */
    public function __construct(SyncLog $syncLog, array $results = [])
    {
        $this->syncLog = $syncLog;
        $this->results = $results;
    }
}