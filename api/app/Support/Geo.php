<?php

namespace App\Support;

use InvalidArgumentException;

final class Geo
{
    public static function pointWkt(float $latitude, float $longitude): string
    {
        self::assertCoordinates($latitude, $longitude);

        return sprintf('SRID=4326;POINT(%s %s)', self::formatCoordinate($longitude), self::formatCoordinate($latitude));
    }

    public static function parsePoint(mixed $value): ?array
    {
        if (is_array($value) && isset($value['lat'], $value['lng']) && is_numeric($value['lat']) && is_numeric($value['lng'])) {
            try {
                self::assertCoordinates((float) $value['lat'], (float) $value['lng']);
            } catch (InvalidArgumentException) {
                return null;
            }

            return ['lat' => (float) $value['lat'], 'lng' => (float) $value['lng']];
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['coordinates'][0], $decoded['coordinates'][1]) && is_numeric($decoded['coordinates'][0]) && is_numeric($decoded['coordinates'][1])) {
            try {
                self::assertCoordinates((float) $decoded['coordinates'][1], (float) $decoded['coordinates'][0]);
            } catch (InvalidArgumentException) {
                return null;
            }

            return ['lat' => (float) $decoded['coordinates'][1], 'lng' => (float) $decoded['coordinates'][0]];
        }

        if (preg_match('/POINT(?:\s+Z)?\s*\(\s*(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\s+(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\s*\)/i', $value, $matches)) {
            try {
                self::assertCoordinates((float) $matches[2], (float) $matches[1]);
            } catch (InvalidArgumentException) {
                return null;
            }

            return ['lat' => (float) $matches[2], 'lng' => (float) $matches[1]];
        }

        return null;
    }

    public static function geometryGeoJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['type'], $decoded['coordinates'])) {
            return $decoded;
        }

        if (preg_match('/MULTILINESTRING\s*\((.*)\)/is', $value, $matches)) {
            $lines = [];
            preg_match_all('/\(([^()]*)\)/', $matches[1], $lineMatches);
            foreach ($lineMatches[1] ?? [] as $line) {
                $coordinates = [];
                foreach (explode(',', $line) as $pair) {
                    $parts = preg_split('/\s+/', trim($pair)) ?: [];
                    if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        try {
                            self::assertCoordinates((float) $parts[1], (float) $parts[0]);
                        } catch (InvalidArgumentException) {
                            return null;
                        }

                        $coordinates[] = [(float) $parts[0], (float) $parts[1]];
                    }
                }
                if (count($coordinates) >= 2) {
                    $lines[] = $coordinates;
                }
            }

            return $lines === [] ? null : ['type' => 'MultiLineString', 'coordinates' => $lines];
        }

        return null;
    }

    public static function multiLineStringWkt(array $geometry): string
    {
        if (($geometry['type'] ?? null) !== 'MultiLineString' || ! is_array($geometry['coordinates'] ?? null) || $geometry['coordinates'] === []) {
            throw new InvalidArgumentException('Route geometry must be a non-empty GeoJSON MultiLineString.');
        }

        $lines = [];
        foreach ($geometry['coordinates'] as $line) {
            if (! is_array($line) || count($line) < 2) {
                throw new InvalidArgumentException('Each route geometry line must contain at least two coordinates.');
            }

            $coordinates = [];
            foreach ($line as $coordinate) {
                if (! is_array($coordinate) || ! isset($coordinate[0], $coordinate[1]) || ! is_numeric($coordinate[0]) || ! is_numeric($coordinate[1])) {
                    throw new InvalidArgumentException('Route geometry coordinates must be [longitude, latitude].');
                }

                $longitude = (float) $coordinate[0];
                $latitude = (float) $coordinate[1];
                self::assertCoordinates($latitude, $longitude);
                $coordinates[] = self::formatCoordinate($longitude).' '.self::formatCoordinate($latitude);
            }

            $lines[] = '('.implode(',', $coordinates).')';
        }

        return 'SRID=4326;MULTILINESTRING('.implode(',', $lines).')';
    }

    /**
     * Convert the bounded GeoJSON geometry shapes accepted for temporary
     * advisories into PostGIS-compatible WKT.  Geometry is optional; callers
     * should prefer explicit entity links when they have them.
     */
    public static function geometryWkt(array $geometry): string
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'Point' && is_array($coordinates) && count($coordinates) === 2) {
            return self::pointWkt((float) $coordinates[1], (float) $coordinates[0]);
        }

        if ($type === 'LineString') {
            return 'SRID=4326;LINESTRING('.self::coordinatePairs($coordinates, 2).')';
        }

        if ($type === 'MultiLineString') {
            $lines = array_map(fn ($line): string => '('.self::coordinatePairs($line, 2).')', self::nestedCoordinates($coordinates, 2));

            return 'SRID=4326;MULTILINESTRING('.implode(',', $lines).')';
        }

        if ($type === 'Polygon') {
            $rings = array_map(fn ($ring): string => '('.self::coordinatePairs($ring, 4, true).')', self::nestedCoordinates($coordinates, 2));

            return 'SRID=4326;POLYGON('.implode(',', $rings).')';
        }

        if ($type === 'MultiPolygon') {
            $polygons = [];
            foreach (self::nestedCoordinates($coordinates, 3) as $polygon) {
                $rings = array_map(fn ($ring): string => '('.self::coordinatePairs($ring, 4, true).')', self::nestedCoordinates($polygon, 2));
                $polygons[] = '('.implode(',', $rings).')';
            }

            return 'SRID=4326;MULTIPOLYGON('.implode(',', $polygons).')';
        }

        throw new InvalidArgumentException('Temporary advisory geometry must be a bounded WGS 84 GeoJSON geometry.');
    }

    public static function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6371000;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return 2 * $earthRadius * asin(min(1, sqrt($a)));
    }

    private static function assertCoordinates(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Coordinates are outside the WGS 84 range. Use latitude/longitude points and [longitude, latitude] GeoJSON coordinates.');
        }
    }

    private static function formatCoordinate(float $coordinate): string
    {
        return rtrim(rtrim(sprintf('%.8F', $coordinate), '0'), '.');
    }

    private static function coordinatePairs(mixed $coordinates, int $minimum, bool $closed = false): string
    {
        if (! is_array($coordinates) || count($coordinates) < $minimum) {
            throw new InvalidArgumentException('Geometry must contain enough coordinate pairs.');
        }

        $pairs = [];
        foreach ($coordinates as $coordinate) {
            if (! is_array($coordinate) || count($coordinate) < 2 || ! is_numeric($coordinate[0]) || ! is_numeric($coordinate[1])) {
                throw new InvalidArgumentException('Geometry coordinates must be [longitude, latitude].');
            }
            self::assertCoordinates((float) $coordinate[1], (float) $coordinate[0]);
            $pairs[] = self::formatCoordinate((float) $coordinate[0]).' '.self::formatCoordinate((float) $coordinate[1]);
        }

        if ($closed && $pairs[0] !== $pairs[array_key_last($pairs)]) {
            $pairs[] = $pairs[0];
        }

        return implode(',', $pairs);
    }

    /** @return list<array<int, mixed>> */
    private static function nestedCoordinates(mixed $coordinates, int $depth): array
    {
        if (! is_array($coordinates) || $depth < 1) {
            throw new InvalidArgumentException('Geometry coordinates are malformed.');
        }
        if ($depth === 1) {
            return $coordinates;
        }

        return array_values(array_map(
            fn ($value): array => self::nestedCoordinates($value, $depth - 1),
            $coordinates,
        ));
    }
}
