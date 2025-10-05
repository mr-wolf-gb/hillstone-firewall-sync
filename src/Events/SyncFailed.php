<?php

namespace MrWolfGb\HillstoneFirewallSync\Events;

use MrWolfGb\HillstoneFirewallSync\Models\SyncLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncFailed
{
    use Dispatchable, SerializesModels;

    public SyncLog $syncLog;
    public \Exception $exception;
    public array $context;

    /**
     * Create a new event instance.
     */
    public function __construct(SyncLog $syncLog, \Exception $exception, array $context = [])
    {
        $this->syncLog = $syncLog;
        $this->exception = $exception;
        $this->context = $context;
    }
}