<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Type;

use Weaver\ORM\DBAL\ConnectionFactory;
use Weaver\ORM\DBAL\Type\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Type\EncryptedStringType;
use Weaver\ORM\Type\EnumStringType;
use Weaver\ORM\Type\IpAddressType;
use Weaver\ORM\Type\JsonType;
use Weaver\ORM\Type\PhoneType;
use Weaver\ORM\Type\TypeRegistry;
use Weaver\ORM\Type\UuidType;

enum Suit: string
{
    case Hearts   = 'hearts';
    case Diamonds = 'diamonds';
    case Clubs    = 'clubs';
    case Spades   = 'spades';
}

enum Direction
{
    case North;
    case South;
    case East;
    case West;
}

final class BuiltinTypesTest extends TestCase
{
    private static function platform(): \Weaver\ORM\DBAL\Platform
    {
        static $platform = null;

        if ($platform === null) {
            $connection = ConnectionFactory::create([
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ]);
            $platform = $connection->getDatabasePlatform();
        }

        return $platform;
    }

    protected function setUp(): void
    {
        TypeRegistry::registerBuiltins();
    }





    public function test_json_type_encodes_array_and_decodes_back(): void
    {
        $type     = Type::getType('json');
        $platform = self::platform();

        $original = ['key' => 'value', 'count' => 42, 'nested' => [1, 2, 3]];

        $dbValue  = $type->convertToDatabaseValue($original, $platform);
        $phpValue = $type->convertToPHPValue($dbValue, $platform);

        self::assertIsString($dbValue);
        self::assertSame($original, $phpValue);
    }





    public function test_json_type_handles_null(): void
    {
        $type     = Type::getType('json');
        $platform = self::platform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }





    public function test_uuid_type_rejects_invalid_format(): void
    {
        $type     = Type::getType('uuid');
        $platform = self::platform();

        $this->expectException(InvalidArgumentException::class);

        $type->convertToDatabaseValue('not-a-uuid', $platform);
    }

    public function test_uuid_type_accepts_valid_uuid(): void
    {
        $type     = Type::getType('uuid');
        $platform = self::platform();

        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $result = $type->convertToDatabaseValue($uuid, $platform);

        self::assertSame($uuid, $result);
        self::assertSame($uuid, $type->convertToPHPValue($result, $platform));
    }





    public function test_ip_address_type_accepts_valid_ipv4(): void
    {
        $type     = Type::getType('ip_address');
        $platform = self::platform();

        $ip = '192.168.1.1';

        self::assertSame($ip, $type->convertToDatabaseValue($ip, $platform));
        self::assertSame($ip, $type->convertToPHPValue($ip, $platform));
    }

    public function test_ip_address_type_accepts_valid_ipv6(): void
    {
        $type     = Type::getType('ip_address');
        $platform = self::platform();

        $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        self::assertSame($ip, $type->convertToDatabaseValue($ip, $platform));
    }





    public function test_ip_address_type_rejects_invalid_ip(): void
    {
        $type     = Type::getType('ip_address');
        $platform = self::platform();

        $this->expectException(InvalidArgumentException::class);

        $type->convertToDatabaseValue('999.999.999.999', $platform);
    }





    public function test_phone_type_strips_non_numeric_chars_and_keeps_plus(): void
    {
        $type     = Type::getType('phone');
        $platform = self::platform();

        $dbValue = $type->convertToDatabaseValue('+1 (800) 555-1234', $platform);

        self::assertSame('+18005551234', $dbValue);

        $phpValue = $type->convertToPHPValue($dbValue, $platform);

        self::assertSame('+18005551234', $phpValue);
    }





    public function test_encrypted_string_type_round_trips_value(): void
    {
        $type     = Type::getType('encrypted_string');
        $platform = self::platform();

        $original = 'super-secret-value';

        $dbValue  = $type->convertToDatabaseValue($original, $platform);


        self::assertNotSame($original, $dbValue);
        self::assertIsString($dbValue);

        $phpValue = $type->convertToPHPValue($dbValue, $platform);

        self::assertSame($original, $phpValue);
    }





    public function test_enum_string_type_converts_backed_enum_to_value(): void
    {
        $type     = Type::getType('enum_string');
        $platform = self::platform();

        $dbValue = $type->convertToDatabaseValue(Suit::Hearts, $platform);

        self::assertSame('hearts', $dbValue);
    }

    public function test_enum_string_type_converts_unit_enum_to_name(): void
    {
        $type     = Type::getType('enum_string');
        $platform = self::platform();

        $dbValue = $type->convertToDatabaseValue(Direction::North, $platform);

        self::assertSame('North', $dbValue);
    }

    public function test_enum_string_type_returns_string_on_read(): void
    {
        $type     = Type::getType('enum_string');
        $platform = self::platform();

        self::assertSame('hearts', $type->convertToPHPValue('hearts', $platform));
    }





    public function test_register_builtins_registers_all_eight_types(): void
    {
        TypeRegistry::registerBuiltins();

        $expected = [
            'ulid',
            'money_cents',
            'json',
            'uuid',
            'ip_address',
            'phone',
            'encrypted_string',
            'enum_string',
        ];

        foreach ($expected as $typeName) {
            self::assertTrue(
                Type::hasType($typeName),
                "Type '$typeName' must be registered after registerBuiltins()",
            );
        }
    }
}
