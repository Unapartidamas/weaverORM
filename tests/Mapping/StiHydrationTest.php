<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\Attribute\TypeColumn;
use Weaver\ORM\Mapping\Attribute\TypeMap;
use Weaver\ORM\Mapping\Attribute\Inheritance;

#[Inheritance('SINGLE_TABLE')]
#[TypeColumn(name: 'kind', type: 'string', length: 50)]
#[TypeMap(['manager' => StiManager::class, 'staff' => StiStaff::class])]
class StiEmployee {}

final class StiManager extends StiEmployee {}
final class StiStaff extends StiEmployee {}

final class StiHydrationTest extends TestCase
{
    private AttributeMapperFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMapperFactory();
    }

    public function test_discriminator_column_name_round_trip(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame('kind', $mapping->discriminatorColumn);
    }

    public function test_discriminator_column_type_round_trip(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame('string', $mapping->discriminatorType);
    }

    public function test_discriminator_map_round_trip(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame(
            ['manager' => StiManager::class, 'staff' => StiStaff::class],
            $mapping->discriminatorMap,
        );
    }

    public function test_resolve_class_from_discriminator_value(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame(StiManager::class, $mapping->resolveClass('manager'));
        self::assertSame(StiStaff::class, $mapping->resolveClass('staff'));
    }

    public function test_resolve_discriminator_value_from_class(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame('manager', $mapping->resolveValue(StiManager::class));
        self::assertSame('staff', $mapping->resolveValue(StiStaff::class));
    }

    public function test_integer_discriminator_value_cast_to_string(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);


        self::assertNull($mapping->resolveClass(1));
    }

    public function test_inheritance_type_is_preserved(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiEmployee::class);

        self::assertNotNull($mapping);
        self::assertSame('SINGLE_TABLE', $mapping->type);
    }
}
