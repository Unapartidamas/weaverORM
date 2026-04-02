<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Geo\GeoSearch;

final class GeoSearchTest extends TestCase
{
    public function test_withinRadius_generates_st_dwithin(): void
    {
        $sql = GeoSearch::withinRadius('location', 40.7128, -74.006, 5000.0);

        self::assertStringContainsString('ST_DWithin', $sql);
        self::assertStringContainsString('ST_MakePoint(-74.006, 40.7128)', $sql);
        self::assertStringContainsString('5000', $sql);
    }

    public function test_nearestPoints_generates_order_by_distance(): void
    {
        $sql = GeoSearch::nearestPoints('location', 40.7128, -74.006, 5);

        self::assertStringContainsString('ORDER BY ST_Distance', $sql);
        self::assertStringContainsString('LIMIT 5', $sql);
        self::assertStringContainsString('ST_MakePoint(-74.006, 40.7128)', $sql);
    }

    public function test_withinBBox_generates_bbox_operator(): void
    {
        $sql = GeoSearch::withinBBox('location', 40.0, -74.5, 41.0, -73.5);

        self::assertStringContainsString('&&', $sql);
        self::assertStringContainsString('ST_MakeEnvelope(-74.5, 40, -73.5, 41, 4326)', $sql);
    }

    public function test_distance_generates_st_distance(): void
    {
        $sql = GeoSearch::distance('location', 40.7128, -74.006);

        self::assertSame(
            'ST_Distance(location, ST_MakePoint(-74.006, 40.7128)::geography)',
            $sql,
        );
    }
}
