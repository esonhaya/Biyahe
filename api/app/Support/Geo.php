<?php

namespace App\Support;

use InvalidArgumentException;

final class Geo
{
    public static function pointWkt(float $latitude, float $longitude): string
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Coordinates are outside the WGS 84 range.');
        }

        return sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude);
    }

    public static function parsePoint(mixed $value): ?array
    {
        if (is_array($value) && isset($value['lat'], $value['lng'])) {
            return ['lat' => (float) $value['lat'], 'lng' => (float) $value['lng']];
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['coordinates'][0], $decoded['coordinates'][1])) {
            return ['lat' => (float) $decoded['coordinates'][1], 'lng' => (float) $decoded['coordinates'][0]];
        }

        if (preg_match('/POINT(?:\s+Z)?\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/i', $value, $matches)) {
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

        if (preg_match('/MULTILINESTRING\s*\(\((.*)\)\)/i', $value, $matches)) {
            $lines = [];
            foreach (preg_split('/\)\s*,\s*\(/', $matches[1]) ?: [] as $line) {
                $coordinates = [];
                foreach (explode(',', $line) as $pair) {
                    $parts = preg_split('/\s+/', trim($pair));
                    if (count($parts) >= 2) {
                        $coordinates[] = [(float) $parts[0], (float) $parts[1]];
                    }
                }
                if ($coordinates !== []) {
                    $lines[] = $coordinates;
                }
            }

            return $lines === [] ? null : ['type' => 'MultiLineString', 'coordinates' => $lines];
        }

        return null;
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
}
