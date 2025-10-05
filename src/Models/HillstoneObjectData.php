<?php

namespace MrWolfGb\HillstoneFirewallSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class HillstoneObjectData extends Model
{
    protected $table = 'hillstone_object_data';

    protected $fillable = [
        'name',
        'ip',
        'is_ipv6',
        'predefined',
        'last_synced_at',
    ];

    protected $casts = [
        'ip' => 'array',
        'is_ipv6' => 'boolean',
        'predefined' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the IP addresses associated with this object data.
     */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(HillstoneObjectDataIP::class);
    }

    /**
     * Get the parent object.
     */
    public function object(): BelongsTo
    {
        return $this->belongsTo(HillstoneObject::class, 'name', 'name');
    }

    /**
     * Create a HillstoneObjectData from API value array with IP processing.
     */
    public static function createFromValueArray(array $valueArray): self
    {
        $objectData = self::create([
            'name' => $valueArray['name'] ?? '',
            'ip' => $valueArray['ip'] ?? [],
            'is_ipv6' => $valueArray['is_ipv6'] ?? false,
            'predefined' => $valueArray['predefined'] ?? false,
            'last_synced_at' => Carbon::now(),
        ]);

        // Process IP data if present
        if (isset($valueArray['ip']) && is_array($valueArray['ip'])) {
            foreach ($valueArray['ip'] as $ipData) {
                HillstoneObjectDataIP::create([
                    'hillstone_object_data_id' => $objectData->id,
                    'ip_addr' => $ipData['ip_addr'] ?? '',
                    'ip_address' => $ipData['ip_address'] ?? '',
                    'netmask' => $ipData['netmask'] ?? '',
                    'flag' => $ipData['flag'] ?? 0,
                ]);
            }
        }

        return $objectData;
    }
}