<?php

namespace App\Services\Routing;

use InvalidArgumentException;

/** Shared OSM access rules for the JSON importer and offline graph builder. */
final class PedestrianOsmPolicy
{
    private const WALKABLE_HIGHWAYS = [
        'footway', 'path', 'pedestrian', 'steps', 'living_street', 'residential',
        'unclassified', 'service', 'track', 'tertiary', 'secondary', 'primary',
        'tertiary_link', 'secondary_link', 'primary_link',
    ];

    private const RESTRICTED_ACCESS = [
        'no', 'private', 'destination', 'customers', 'permit', 'agricultural',
        'forestry', 'delivery', 'use_sidepath', 'discouraged',
    ];

    public static function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            throw new InvalidArgumentException('OSM tags must be an object.');
        }

        $normalized = [];
        foreach ($tags as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidArgumentException('OSM tag names and values must be strings.');
            }
            $normalized[$key] = strtolower(trim($value));
        }

        return $normalized;
    }

    public static function walkableWay(array $tags): bool
    {
        $highway = $tags['highway'] ?? null;
        if (! is_string($highway) || ($tags['area'] ?? null) === 'yes'
            || in_array($highway, ['motorway', 'motorway_link', 'trunk', 'trunk_link', 'construction', 'proposed', 'raceway'], true)
            || ! in_array($highway, self::WALKABLE_HIGHWAYS, true)
            || self::privateServiceWay($tags)
            || self::hasUnsupportedConditionalAccess($tags)) {
            return false;
        }
        if (isset($tags['oneway:foot']) && ! in_array($tags['oneway:foot'], ['yes', 'no', '1', '0', 'true', 'false', '-1', 'reverse'], true)) {
            return false;
        }
        foreach (['foot:forward', 'foot:backward'] as $direction) {
            if (isset($tags[$direction]) && ! self::supportedAccessValue($tags[$direction])) {
                return false;
            }
        }

        return self::supportedAccessValue($tags['foot'] ?? null)
            && ! in_array($tags['foot'] ?? null, self::RESTRICTED_ACCESS, true)
            && (isset($tags['foot']) || (self::supportedAccessValue($tags['access'] ?? null)
                && ! in_array($tags['access'] ?? null, self::RESTRICTED_ACCESS, true)));
    }

    /** @return array{bool, bool} */
    public static function directions(array $tags): array
    {
        $forward = true;
        $backward = true;
        if (in_array($tags['oneway:foot'] ?? null, ['yes', '1', 'true'], true)) {
            $backward = false;
        } elseif (in_array($tags['oneway:foot'] ?? null, ['-1', 'reverse'], true)) {
            $forward = false;
        }
        if (in_array($tags['foot:forward'] ?? null, self::RESTRICTED_ACCESS, true)) {
            $forward = false;
        }
        if (in_array($tags['foot:backward'] ?? null, self::RESTRICTED_ACCESS, true)) {
            $backward = false;
        }

        return [$forward, $backward];
    }

    public static function blockedNode(array $tags): bool
    {
        if (isset($tags['foot:conditional']) || isset($tags['access:conditional'])
            || ! self::supportedAccessValue($tags['foot'] ?? null)
            || (! isset($tags['foot']) && ! self::supportedAccessValue($tags['access'] ?? null))
            || in_array($tags['foot'] ?? null, self::RESTRICTED_ACCESS, true)
            || (! isset($tags['foot']) && in_array($tags['access'] ?? null, self::RESTRICTED_ACCESS, true))) {
            return true;
        }

        $barrier = $tags['barrier'] ?? null;
        if ($barrier === null) {
            return false;
        }

        return ! in_array($barrier, [
            'gate', 'lift_gate', 'swing_gate', 'sliding_gate', 'entrance', 'bollard',
            'cycle_barrier', 'stile', 'kissing_gate', 'turnstile',
        ], true);
    }

    public static function explicitlyRestrictedWay(array $tags): bool
    {
        return self::hasUnsupportedConditionalAccess($tags)
            || ! self::supportedAccessValue($tags['foot'] ?? null)
            || (! isset($tags['foot']) && ! self::supportedAccessValue($tags['access'] ?? null))
            || self::privateServiceWay($tags)
            || in_array($tags['foot'] ?? null, self::RESTRICTED_ACCESS, true)
            || (! isset($tags['foot']) && in_array($tags['access'] ?? null, self::RESTRICTED_ACCESS, true))
            || in_array($tags['foot:forward'] ?? null, self::RESTRICTED_ACCESS, true)
            || in_array($tags['foot:backward'] ?? null, self::RESTRICTED_ACCESS, true);
    }

    public static function insideScope(float $latitude, float $longitude, array $scope): bool
    {
        return $latitude >= (float) $scope['min_latitude'] && $latitude <= (float) $scope['max_latitude']
            && $longitude >= (float) $scope['min_longitude'] && $longitude <= (float) $scope['max_longitude'];
    }

    private static function supportedAccessValue(?string $value): bool
    {
        return $value === null || in_array($value, [
            'yes', 'permissive', 'designated', 'official', ...self::RESTRICTED_ACCESS,
        ], true);
    }

    private static function privateServiceWay(array $tags): bool
    {
        $restrictedServiceTypes = ['driveway', 'private', 'drive-through', 'emergency_access'];
        $explicitFootAccess = in_array($tags['foot'] ?? null, ['yes', 'permissive', 'designated', 'official'], true);

        return ($tags['highway'] ?? null) === 'service'
            && in_array($tags['service'] ?? null, $restrictedServiceTypes, true)
            && ! $explicitFootAccess;
    }

    private static function hasUnsupportedConditionalAccess(array $tags): bool
    {
        foreach ([
            'foot:conditional', 'access:conditional',
            'foot:forward:conditional', 'foot:backward:conditional',
            'access:forward:conditional', 'access:backward:conditional',
            'oneway:foot:conditional',
        ] as $key) {
            if (isset($tags[$key])) {
                return true;
            }
        }

        return false;
    }
}
