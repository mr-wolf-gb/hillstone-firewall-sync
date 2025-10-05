<?php

namespace MrWolfGb\HillstoneFirewall\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class AddressBookResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'name' => $this->resource['name'] ?? '',
            'member' => $this->processMemberData($this->resource['member'] ?? []),
            'is_ipv6' => $this->resource['is_ipv6'] ?? false,
            'predefined' => $this->resource['predefined'] ?? false,
            'description' => $this->resource['description'] ?? '',
            'type' => $this->resource['type'] ?? 'address',
            'last_modified' => $this->formatTimestamp($this->resource['last_modified'] ?? null),
            'created_at' => $this->formatTimestamp($this->resource['created_at'] ?? null),
            'updated_at' => $this->formatTimestamp($this->resource['updated_at'] ?? null),
        ];
    }

    /**
     * Process member data to ensure consistent structure
     */
    protected function processMemberData($memberData): array
    {
        if (empty($memberData)) {
            return [];
        }

        // Handle different member data formats
        if (is_string($memberData)) {
            // Single member as string
            return [$this->processSingleMember($memberData)];
        }

        if (is_array($memberData)) {
            // Multiple members as array
            return array_map([$this, 'processSingleMember'], $memberData);
        }

        return [];
    }

    /**
     * Process a single member entry
     */
    protected function processSingleMember($member): array
    {
        if (is_string($member)) {
            return [
                'name' => $member,
                'type' => $this->detectMemberType($member),
                'value' => $member,
            ];
        }

        if (is_array($member)) {
            return [
                'name' => $member['name'] ?? $member['value'] ?? '',
                'type' => $member['type'] ?? $this->detectMemberType($member['name'] ?? $member['value'] ?? ''),
                'value' => $member['value'] ?? $member['name'] ?? '',
                'description' => $member['description'] ?? '',
            ];
        }

        return [
            'name' => (string) $member,
            'type' => 'unknown',
            'value' => (string) $member,
        ];
    }

    /**
     * Detect the type of a member based on its value
     */
    protected function detectMemberType(string $value): string
    {
        // Check if it's an IP address
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }

        // Check if it's a CIDR notation
        if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $value)) {
            return 'ipv4_cidr';
        }

        if (preg_match('/^([0-9a-fA-F:]+)\/\d{1,3}$/', $value)) {
            return 'ipv6_cidr';
        }

        // Check if it's a range
        if (preg_match('/^(\d{1,3}\.){3}\d{1,3}-(\d{1,3}\.){3}\d{1,3}$/', $value)) {
            return 'ipv4_range';
        }

        // Check if it's a hostname/FQDN
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $value)) {
            return 'hostname';
        }

        return 'reference';
    }

    /**
     * Format timestamp to ISO 8601 string
     */
    protected function formatTimestamp($timestamp): ?string
    {
        if (empty($timestamp)) {
            return null;
        }

        try {
            if ($timestamp instanceof Carbon) {
                return $timestamp->toISOString();
            }

            if (is_string($timestamp)) {
                return Carbon::parse($timestamp)->toISOString();
            }

            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp)->toISOString();
            }
        } catch (\Exception $e) {
            // If timestamp parsing fails, return null
            return null;
        }

        return null;
    }

    /**
     * Create a resource collection from array data
     */
    public static function collection($resource): Collection
    {
        if (!is_array($resource) && !($resource instanceof Collection)) {
            return collect([]);
        }

        $collection = is_array($resource) ? collect($resource) : $resource;

        return $collection->map(function ($item) {
            return new static($item);
        });
    }

    /**
     * Transform collection to array format
     */
    public static function collectionToArray($resource, $request = null): array
    {
        return static::collection($resource)
            ->map(function ($item) use ($request) {
                return $item->toArray($request);
            })
            ->toArray();
    }

    /**
     * Validate address book object data
     */
    public function validate(): array
    {
        $errors = [];

        // Validate name
        if (empty($this->resource['name'])) {
            $errors[] = 'Name is required';
        } elseif (strlen($this->resource['name']) > 255) {
            $errors[] = 'Name must not exceed 255 characters';
        }

        // Validate member data
        $memberData = $this->resource['member'] ?? [];
        if (empty($memberData)) {
            $errors[] = 'At least one member is required';
        } else {
            $memberErrors = $this->validateMembers($memberData);
            $errors = array_merge($errors, $memberErrors);
        }

        return $errors;
    }

    /**
     * Validate member data
     */
    protected function validateMembers($memberData): array
    {
        $errors = [];

        if (!is_array($memberData)) {
            $memberData = [$memberData];
        }

        foreach ($memberData as $index => $member) {
            $memberValue = is_array($member) ? ($member['value'] ?? $member['name'] ?? '') : (string) $member;

            if (empty($memberValue)) {
                $errors[] = "Member at index {$index} has no value";
                continue;
            }

            // Validate IP addresses if they appear to be IPs
            $memberType = $this->detectMemberType($memberValue);
            
            if (in_array($memberType, ['ipv4', 'ipv6'])) {
                if (!filter_var($memberValue, FILTER_VALIDATE_IP)) {
                    $errors[] = "Member '{$memberValue}' is not a valid IP address";
                }
            }

            // Validate CIDR notation
            if (in_array($memberType, ['ipv4_cidr', 'ipv6_cidr'])) {
                if (!$this->isValidCidr($memberValue)) {
                    $errors[] = "Member '{$memberValue}' is not a valid CIDR notation";
                }
            }
        }

        return $errors;
    }

    /**
     * Check if a string is valid CIDR notation
     */
    protected function isValidCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        
        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $prefix] = $parts;

        // Validate IP part
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Validate prefix
        $prefixInt = (int) $prefix;
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $prefixInt >= 0 && $prefixInt <= 32;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefixInt >= 0 && $prefixInt <= 128;
        }

        return false;
    }

    /**
     * Get summary information about the address book object
     */
    public function getSummary(): array
    {
        $memberData = $this->resource['member'] ?? [];
        $processedMembers = $this->processMemberData($memberData);

        $memberTypes = array_count_values(
            array_column($processedMembers, 'type')
        );

        return [
            'name' => $this->resource['name'] ?? '',
            'member_count' => count($processedMembers),
            'member_types' => $memberTypes,
            'is_ipv6' => $this->resource['is_ipv6'] ?? false,
            'predefined' => $this->resource['predefined'] ?? false,
            'has_description' => !empty($this->resource['description']),
        ];
    }

    /**
     * Convert to database-ready format
     */
    public function toDatabaseFormat(): array
    {
        return [
            'name' => $this->resource['name'] ?? '',
            'member' => json_encode($this->processMemberData($this->resource['member'] ?? [])),
            'is_ipv6' => $this->resource['is_ipv6'] ?? false,
            'predefined' => $this->resource['predefined'] ?? false,
            'last_synced_at' => now(),
        ];
    }
}