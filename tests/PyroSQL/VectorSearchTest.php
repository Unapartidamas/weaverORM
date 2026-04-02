<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\Vector\VectorSearch;

final class VectorSearchTest extends TestCase
{
    public function test_format_vector_produces_bracket_notation(): void
    {
        self::assertSame('[0.1,0.2,0.3]', VectorSearch::formatVector([0.1, 0.2, 0.3]));
    }

    public function test_format_vector_single_element(): void
    {
        self::assertSame('[0.5]', VectorSearch::formatVector([0.5]));
    }

    public function test_distance_operator_cosine_returns_arrow(): void
    {
        self::assertSame('<=>', VectorSearch::distanceOperator('cosine'));
    }

    public function test_distance_operator_l2_returns_l2(): void
    {
        self::assertSame('<->', VectorSearch::distanceOperator('l2'));
    }

    public function test_distance_operator_dot_returns_dot(): void
    {
        self::assertSame('<#>', VectorSearch::distanceOperator('dot'));
    }

    public function test_distance_operator_throws_for_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VectorSearch::distanceOperator('jaccard');
    }

    public function test_nearest_neighbors_returns_order_by_fragment(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5);

        self::assertArrayHasKey('orderBy', $result);
        self::assertArrayHasKey('limit', $result);
        self::assertArrayHasKey('distanceColumn', $result);
        self::assertSame(5, $result['limit']);
        self::assertStringContainsString('<=>', $result['orderBy']);
    }

    public function test_nearest_neighbors_uses_specified_distance_op(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5, distanceOp: 'l2');

        self::assertStringContainsString('<->', $result['orderBy']);
    }





    public function test_nearest_neighbors_returns_all_required_array_keys(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2, 0.3], k: 10);

        self::assertArrayHasKey('orderBy', $result);
        self::assertArrayHasKey('limit', $result);
        self::assertArrayHasKey('distanceColumn', $result);
    }

    public function test_nearest_neighbors_cosine_uses_fat_arrow_operator(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5, distanceOp: 'cosine');

        self::assertStringContainsString('<=>', $result['orderBy']);
        self::assertStringContainsString('<=>', $result['distanceColumn']);
    }

    public function test_nearest_neighbors_l2_uses_l2_operator(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5, distanceOp: 'l2');

        self::assertStringContainsString('<->', $result['orderBy']);
        self::assertStringContainsString('<->', $result['distanceColumn']);
    }

    public function test_nearest_neighbors_dot_uses_hash_gt_operator(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5, distanceOp: 'dot');

        self::assertStringContainsString('<#>', $result['orderBy']);
        self::assertStringContainsString('<#>', $result['distanceColumn']);
    }

    public function test_nearest_neighbors_unknown_op_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VectorSearch::nearestNeighbors('embedding', [0.1, 0.2], k: 5, distanceOp: 'manhattan');
    }

    public function test_nearest_neighbors_limit_matches_k(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1], k: 42);

        self::assertSame(42, $result['limit']);
    }

    public function test_nearest_neighbors_distance_column_has_alias(): void
    {
        $result = VectorSearch::nearestNeighbors('embedding', [0.1], k: 5);

        self::assertStringContainsString('AS _distance', $result['distanceColumn']);
    }





    public function test_format_vector_coerces_non_numeric_to_zero(): void
    {
        $result = VectorSearch::formatVector(['not-a-number', 'also-bad', 0.5]);

        self::assertSame('[0,0,0.5]', $result);
    }

    public function test_format_vector_coerces_null_to_zero(): void
    {
        $result = VectorSearch::formatVector([null, 0.1]);

        self::assertSame('[0,0.1]', $result);
    }

    public function test_format_vector_handles_empty_array(): void
    {
        self::assertSame('[]', VectorSearch::formatVector([]));
    }
}
