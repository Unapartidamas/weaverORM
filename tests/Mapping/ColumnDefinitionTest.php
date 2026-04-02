<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\ColumnDefinition;

final class ColumnDefinitionTest extends TestCase
{




    public function test_basic_construction(): void
    {
        $col = new ColumnDefinition('email', 'email', 'string');

        $this->assertSame('email', $col->getColumn());
        $this->assertSame('email', $col->getProperty());
        $this->assertSame('string', $col->getType());
        $this->assertFalse($col->isPrimary());
        $this->assertFalse($col->isAutoIncrement());
        $this->assertFalse($col->isNullable());
        $this->assertNull($col->getDefault());
    }

    public function test_column_and_property_can_differ(): void
    {
        $col = new ColumnDefinition('created_at', 'createdAt', 'datetime_immutable');

        $this->assertSame('created_at', $col->getColumn());
        $this->assertSame('createdAt', $col->getProperty());
    }





    public function test_primary_key_column(): void
    {
        $col = new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true);

        $this->assertTrue($col->isPrimary());
        $this->assertTrue($col->isAutoIncrement());
    }

    public function test_primary_without_auto_increment(): void
    {
        $col = new ColumnDefinition('uuid', 'uuid', 'string', primary: true);

        $this->assertTrue($col->isPrimary());
        $this->assertFalse($col->isAutoIncrement());
    }





    public function test_nullable_column(): void
    {
        $col = new ColumnDefinition('deleted_at', 'deletedAt', 'datetime_immutable', nullable: true);

        $this->assertTrue($col->isNullable());
    }

    public function test_non_nullable_by_default(): void
    {
        $col = new ColumnDefinition('title', 'title', 'string');

        $this->assertFalse($col->isNullable());
    }





    public function test_length_is_stored(): void
    {
        $col = new ColumnDefinition('name', 'name', 'string', length: 100);

        $this->assertSame(100, $col->getLength());
    }

    public function test_length_is_null_by_default(): void
    {
        $col = new ColumnDefinition('flag', 'flag', 'boolean');

        $this->assertNull($col->getLength());
    }

    public function test_precision_and_scale_are_stored(): void
    {
        $col = new ColumnDefinition('price', 'price', 'decimal', precision: 10, scale: 2);

        $this->assertSame(10, $col->getPrecision());
        $this->assertSame(2, $col->getScale());
    }





    public function test_unsigned_flag(): void
    {
        $col = new ColumnDefinition('quantity', 'quantity', 'integer', unsigned: true);

        $this->assertTrue($col->isUnsigned());
    }

    public function test_not_unsigned_by_default(): void
    {
        $col = new ColumnDefinition('quantity', 'quantity', 'integer');

        $this->assertFalse($col->isUnsigned());
    }





    public function test_default_value_is_stored(): void
    {
        $col = new ColumnDefinition('role', 'role', 'string', default: 'user');

        $this->assertSame('user', $col->getDefault());
    }

    public function test_default_null_when_not_supplied(): void
    {
        $col = new ColumnDefinition('role', 'role', 'string');

        $this->assertNull($col->getDefault());
    }





    public function test_generated_column_not_persistable(): void
    {
        $col = new ColumnDefinition('full_name', 'fullName', 'string', generated: true);

        $this->assertTrue($col->isGenerated());
        $this->assertFalse($col->isVirtual());
    }

    public function test_virtual_column(): void
    {
        $col = new ColumnDefinition('computed', 'computed', 'string', virtual: true);

        $this->assertTrue($col->isVirtual());
        $this->assertFalse($col->isGenerated());
    }

    public function test_generated_and_virtual_are_independent(): void
    {
        $gen  = new ColumnDefinition('c1', 'c1', 'string', generated: true);
        $virt = new ColumnDefinition('c2', 'c2', 'string', virtual: true);

        $this->assertFalse($gen->isVirtual());
        $this->assertFalse($virt->isGenerated());
    }





    public function test_enum_class_is_stored(): void
    {
        $col = new ColumnDefinition('status', 'status', 'string', enumClass: \BackedEnum::class);

        $this->assertSame(\BackedEnum::class, $col->getEnumClass());
    }

    public function test_enum_class_is_null_by_default(): void
    {
        $col = new ColumnDefinition('status', 'status', 'string');

        $this->assertNull($col->getEnumClass());
    }





    public function test_version_column(): void
    {
        $col = new ColumnDefinition('version', 'version', 'integer', version: true);

        $this->assertTrue($col->isVersion());
    }

    public function test_not_version_by_default(): void
    {
        $col = new ColumnDefinition('version', 'version', 'integer');

        $this->assertFalse($col->isVersion());
    }





    public function test_charset_and_collation_are_stored(): void
    {
        $col = new ColumnDefinition(
            'body',
            'body',
            'string',
            charset: 'utf8mb4',
            collation: 'utf8mb4_unicode_ci',
        );

        $this->assertSame('utf8mb4', $col->getCharset());
        $this->assertSame('utf8mb4_unicode_ci', $col->getCollation());
    }

    public function test_charset_and_collation_are_null_by_default(): void
    {
        $col = new ColumnDefinition('body', 'body', 'string');

        $this->assertNull($col->getCharset());
        $this->assertNull($col->getCollation());
    }

    public function test_comment_is_stored(): void
    {
        $col = new ColumnDefinition('body', 'body', 'string', comment: 'The comment text');

        $this->assertSame('The comment text', $col->getComment());
    }

    public function test_comment_is_null_by_default(): void
    {
        $col = new ColumnDefinition('body', 'body', 'string');

        $this->assertNull($col->getComment());
    }
}
