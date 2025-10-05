<?php

namespace MrWolfGb\HillstoneFirewallSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class HillstoneObjectDataIP extends Model
{
    protected $table = 'hillstone_object_data_ips';

    protected $fillable = [
        'hillstone_object_data_id',
        'ip_addr',
        'ip_address',
        'netmask',
        'flag',
    ];

    protected $casts = [
        'flag' => 'integer',
    ];

    /**
     * Get the parent object data.
     */
    public function objectData(): BelongsTo
    {
        return $this->belongsTo(HillstoneObjectData::class, 'hillstone_object_data_id');
    }

    /**
     * Convert long integer to IP address.
     */
    public function longToIp(int $long): string
    {
        return long2ip($long);
    }

    /**
     * Convert IP address to long integer.
     */
    public function ipToLong(string $ip): int
    {
        return ip2long($ip);
    }

    /**
     * Scope to search by IP address.
     */
    public function scopeByIpAddress(Builder $query, string $ipAddress): Builder
    {
        return $query->where('ip_address', $ipAddress);
    }

    /**
     * Scope to search by IP address range.
     */
    public function scopeByIpRange(Builder $query, string $startIp, string $endIp): Builder
    {
        $startLong = ip2long($startIp);
        $endLong = ip2long($endIp);
        
        return $query->whereRaw('INET_ATON(ip_address) BETWEEN ? AND ?', [$startLong, $endLong]);
    }

    /**
     * Scope to search by subnet.
     */
    public function scopeBySubnet(Builder $query, string $subnet): Builder
    {
        [$network, $cidr] = explode('/', $subnet);
        $mask = ~((1 << (32 - $cidr)) - 1);
        $networkLong = ip2long($network) & $mask;
        $broadcastLong = $networkLong | ~$mask;
        
        return $query->whereRaw('INET_ATON(ip_address) BETWEEN ? AND ?', [$networkLong, $broadcastLong]);
    }
}