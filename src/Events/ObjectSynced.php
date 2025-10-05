<?php

namespace MrWolfGb\HillstoneFirewallSync\Events;

use MrWolfGb\HillstoneFirewallSync\Models\HillstoneObject;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ObjectSynced
{
    use Dispatchable, SerializesModels;

    public HillstoneObject $object;
    public string $action; // 'created', 'updated', 'deleted'
    public array $changes;

    /**
     * Create a new event instance.
     */
    public function __construct(HillstoneObject $object, string $action, array $changes = [])
    {
        $this->object = $object;
        $this->action = $action;
        $this->changes = $changes;
    }
}