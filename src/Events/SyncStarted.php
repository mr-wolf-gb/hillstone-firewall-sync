<?php

namespace MrWolfGb\HillstoneFirewallSync\Events;

use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncStarted
{
    use Dispatchable, SerializesModels;

    public SyncLog $syncLog;
    public array $options;

    /**
     * Create a new event instance.
     */
    public function __construct(SyncLog $syncLog, array $options = [])
    {
        $this->syncLog = $syncLog;
        $this->options = $options;
    }
}