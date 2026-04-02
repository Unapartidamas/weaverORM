<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\Attribute\TypeColumn;
use Weaver\ORM\Mapping\Attribute\TypeMap;
use Weaver\ORM\Mapping\Attribute\Inheritance;
use Weaver\ORM\Mapping\InheritanceMapping;

#[Inheritance('SINGLE_TABLE')]
#[TypeColumn(name: 'type')]
#[TypeMap(['admin' => StiAdminUser::class, 'user' => StiRegularUser::class])]
class StiBaseUser {}

final class StiAdminUser extends StiBaseUser {}
final class StiRegularUser extends StiBaseUser {}

final class StiAttributeTest extends TestCase
{
    private AttributeMapperFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMapperFactory();
    }

    public function test_buildInheritanceMapping_returns_inheritance_mapping(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertInstanceOf(InheritanceMapping::class, $mapping);
    }

    public function test_buildInheritanceMapping_returns_null_without_attribute(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiAdminUser::class);

        self::assertNull($mapping);
    }

    public function test_inheritance_type_is_single_table(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertSame('SINGLE_TABLE', $mapping->type);
    }

    public function test_discriminator_column_name(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertSame('type', $mapping->discriminatorColumn);
    }

    public function test_discriminator_map_is_populated(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertSame(
            ['admin' => StiAdminUser::class, 'user' => StiRegularUser::class],
            $mapping->discriminatorMap,
        );
    }

    public function test_resolveClass_returns_correct_class(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertSame(StiAdminUser::class, $mapping->resolveClass('admin'));
        self::assertSame(StiRegularUser::class, $mapping->resolveClass('user'));
    }

    public function test_resolveClass_returns_null_for_unknown_discriminator(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertNull($mapping->resolveClass('unknown'));
    }

    public function test_resolveValue_returns_correct_discriminator(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertSame('admin', $mapping->resolveValue(StiAdminUser::class));
        self::assertSame('user', $mapping->resolveValue(StiRegularUser::class));
    }

    public function test_resolveValue_returns_null_for_unknown_class(): void
    {
        $mapping = $this->factory->buildInheritanceMapping(StiBaseUser::class);

        self::assertNotNull($mapping);
        self::assertNull($mapping->resolveValue(\stdClass::class));
    }
}
