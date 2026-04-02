<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Geo;

final class GeoSearch
{
    private function __construct() {}

    public static function withinRadius(string $column, float $lat, float $lon, float $radiusMeters): string
    {
        return "ST_DWithin({$column}, ST_MakePoint({$lon}, {$lat})::geography, {$radiusMeters})";
    }

    public static function nearestPoints(string $column, float $lat, float $lon, int $limit = 10): string
    {
        return "ORDER BY ST_Distance({$column}, ST_MakePoint({$lon}, {$lat})::geography) ASC LIMIT {$limit}";
    }

    public static function withinBBox(string $column, float $minLat, float $minLon, float $maxLat, float $maxLon): string
    {
        return "{$column} && ST_MakeEnvelope({$minLon}, {$minLat}, {$maxLon}, {$maxLat}, 4326)";
    }

    public static function distance(string $column, float $lat, float $lon): string
    {
        return "ST_Distance({$column}, ST_MakePoint({$lon}, {$lat})::geography)";
    }
}
