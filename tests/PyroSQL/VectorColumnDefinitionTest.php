<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\PyroSQL\Vector\VectorColumnDefinition;

final class VectorColumnDefinitionTest extends TestCase
{




    public function test_constructs_correctly_with_dimensions(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
        );

        self::assertSame(1536, $def->getDimensions());
    }





    public function test_get_dimensions_returns_correct_value(): void
    {
        $def = new VectorColumnDefinition(
            column:     'vec',
            property:   'vec',
            dimensions: 768,
        );

        self::assertSame(768, $def->getDimensions());
    }

    public function test_get_dimensions_returns_value_for_small_model(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 384,
        );

        self::assertSame(384, $def->getDimensions());
    }





    public function test_extends_column_definition(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
        );

        self::assertInstanceOf(ColumnDefinition::class, $def);
    }

    public function test_get_column_returns_correct_db_column_name(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embeddingProp',
            dimensions: 1536,
        );

        self::assertSame('embedding', $def->getColumn());
    }

    public function test_get_type_returns_vector(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
        );

        self::assertSame('vector', $def->getType());
    }

    public function test_is_nullable_defaults_to_true(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
        );

        self::assertTrue($def->isNullable());
    }

    public function test_nullable_can_be_set_to_false(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
            nullable:   false,
        );

        self::assertFalse($def->isNullable());
    }

    public function test_comment_is_forwarded_to_parent(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
            comment:    'OpenAI text-embedding-3-small output',
        );

        self::assertSame('OpenAI text-embedding-3-small output', $def->getComment());
    }







    public function test_sql_fragment_combines_column_and_dimensions(): void
    {
        $def = new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 384,
        );


        $fragment = sprintf('%s VECTOR(%d)', $def->getColumn(), $def->getDimensions());

        self::assertSame('embedding VECTOR(384)', $fragment);
    }

    public function test_sql_fragment_uses_correct_dimensions_for_large_model(): void
    {
        $def = new VectorColumnDefinition(
            column:     'vec',
            property:   'vec',
            dimensions: 1536,
        );

        $fragment = sprintf('%s VECTOR(%d)', $def->getColumn(), $def->getDimensions());

        self::assertSame('vec VECTOR(1536)', $fragment);
    }

    public function test_dimensions_are_stored_correctly_via_get_dimensions(): void
    {
        foreach ([128, 256, 384, 768, 1536, 3072] as $dims) {
            $def = new VectorColumnDefinition(
                column:     'vec',
                property:   'vec',
                dimensions: $dims,
            );

            self::assertSame($dims, $def->getDimensions(), "Expected {$dims} dimensions");
        }
    }
}
