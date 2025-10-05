<?php

namespace MrWolfGb\HillstoneFirewallSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class HillstoneObject extends Model
{
    protected $table = 'hillstone_objects';

    protected $fillable = [
        'name',
        'member',
        'is_ipv6',
        'predefined',
        'last_synced_at',
    ];

    protected $casts = [
        'member' => 'array',
        'is_ipv6' => 'boolean',
        'predefined' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the object data associated with this object.
     */
    public function objectData(): HasOne
    {
        return $this->hasOne(HillstoneObjectData::class, 'name', 'name');
    }

    /**
     * Create a HillstoneObject from API value array.
     */
    public static function createFromValueArray(array $valueArray): self
    {
        return self::create([
            'name' => $valueArray['name'] ?? '',
            'member' => $valueArray['member'] ?? [],
            'is_ipv6' => $valueArray['is_ipv6'] ?? false,
            'predefined' => $valueArray['predefined'] ?? false,
            'last_synced_at' => Carbon::now(),
        ]);
    }
}