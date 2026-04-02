<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\DBAL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\Platform\SqlitePlatform;
use Weaver\ORM\DBAL\Type\BooleanType;
use Weaver\ORM\DBAL\Type\DateTimeType;
use Weaver\ORM\DBAL\Type\FloatType;
use Weaver\ORM\DBAL\Type\IntegerType;
use Weaver\ORM\DBAL\Type\JsonType;
use Weaver\ORM\DBAL\Type\StringType;
use Weaver\ORM\DBAL\Type\Type;

final class TypeTest extends TestCase
{
    private SqlitePlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new SqlitePlatform();
    }

    public function test_integer_type_converts(): void
    {
        $type = new IntegerType();

        self::assertSame(42, $type->convertToPHPValue('42', $this->platform));
        self::assertSame(42, $type->convertToDatabaseValue(42, $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function test_float_type_converts(): void
    {
        $type = new FloatType();

        self::assertSame(3.14, $type->convertToPHPValue('3.14', $this->platform));
        self::assertSame(3.14, $type->convertToDatabaseValue(3.14, $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function test_boolean_type_converts(): void
    {
        $type = new BooleanType();

        self::assertTrue($type->convertToPHPValue(1, $this->platform));
        self::assertFalse($type->convertToPHPValue(0, $this->platform));
        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function test_datetime_type_converts(): void
    {
        $type = new DateTimeType();

        $result = $type->convertToPHPValue('2025-01-15 10:30:00', $this->platform);
        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertSame('2025-01-15', $result->format('Y-m-d'));

        $dbValue = $type->convertToDatabaseValue($result, $this->platform);
        self::assertSame('2025-01-15 10:30:00', $dbValue);

        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function test_json_type_converts(): void
    {
        $type = new JsonType();

        $data = ['key' => 'value', 'nested' => [1, 2, 3]];
        $json = $type->convertToDatabaseValue($data, $this->platform);
        self::assertIsString($json);

        $decoded = $type->convertToPHPValue($json, $this->platform);
        self::assertSame($data, $decoded);

        self::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    public function test_string_type_passthrough(): void
    {
        $type = new StringType();

        self::assertSame('hello', $type->convertToPHPValue('hello', $this->platform));
        self::assertSame('hello', $type->convertToDatabaseValue('hello', $this->platform));
    }

    public function test_get_type_returns_registered(): void
    {
        Type::resetTypes();
        Type::registerBuiltins();

        self::assertInstanceOf(IntegerType::class, Type::getType('integer'));
        self::assertInstanceOf(StringType::class, Type::getType('string'));
        self::assertTrue(Type::hasType('json'));
        self::assertFalse(Type::hasType('nonexistent'));
    }

    public function test_get_type_unknown_throws(): void
    {
        Type::resetTypes();
        Type::registerBuiltins();

        $this->expectException(\InvalidArgumentException::class);
        Type::getType('nonexistent');
    }
}
