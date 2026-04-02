<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\EmbeddedDefinition;
use Weaver\ORM\Tests\Fixture\Embeddable\Address;
use Weaver\ORM\Tests\Fixture\Embeddable\Coordinates;
use Weaver\ORM\Tests\Fixture\Embeddable\Warehouse;

final class NestedEmbeddableTest extends TestCase
{
    private AttributeMapperFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMapperFactory();
    }

    public function test_nested_embeddable_flattens_with_combined_prefix(): void
    {
        $mapper = $this->factory->build(Warehouse::class);
        $columns = $mapper->getColumns();

        $columnNames = array_map(fn ($c) => $c->getColumn(), $columns);

        self::assertContains('addr_street', $columnNames);
        self::assertContains('addr_city', $columnNames);
        self::assertContains('addr_coord_lat', $columnNames);
        self::assertContains('addr_coord_lng', $columnNames);
    }

    public function test_hydration_recursively_creates_nested_objects(): void
    {
        $mapper = $this->factory->build(Warehouse::class);

        $embedded = $mapper->getEmbedded();
        self::assertCount(1, $embedded);

        $addressDef = $embedded[0];
        self::assertSame('address', $addressDef->property);
        self::assertSame(Address::class, $addressDef->embeddableClass);
        self::assertCount(1, $addressDef->nestedEmbeddables);

        $coordDef = $addressDef->nestedEmbeddables[0];
        self::assertSame('coordinates', $coordDef->property);
        self::assertSame(Coordinates::class, $coordDef->embeddableClass);
        self::assertSame('addr_coord_', $coordDef->prefix);
    }

    public function test_two_levels_deep_embeddable_works(): void
    {
        $mapper = $this->factory->build(Warehouse::class);

        $embedded = $mapper->getEmbedded();
        $addressDef = $embedded[0];

        self::assertCount(2, $addressDef->columns);

        $addressColNames = array_map(fn ($c) => $c->getColumn(), $addressDef->columns);
        self::assertContains('addr_street', $addressColNames);
        self::assertContains('addr_city', $addressColNames);

        $coordDef = $addressDef->nestedEmbeddables[0];
        self::assertCount(2, $coordDef->columns);

        $coordColNames = array_map(fn ($c) => $c->getColumn(), $coordDef->columns);
        self::assertContains('addr_coord_lat', $coordColNames);
        self::assertContains('addr_coord_lng', $coordColNames);
    }
}
