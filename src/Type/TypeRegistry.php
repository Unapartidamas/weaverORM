<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Type\Type;

final class TypeRegistry
{

    public static function register(array $types): void
    {
        foreach ($types as $name => $typeClass) {
            self::registerOne($name, $typeClass);
        }
    }

    public static function registerOne(string $name, string $typeClass): void
    {
        if (Type::hasType($name)) {
            return;
        }

        Type::registerType($name, new $typeClass());
    }

    public static function registerBuiltins(): void
    {
        self::register([
            'ulid'             => UlidType::class,
            'money_cents'      => MoneyType::class,
            'json'             => JsonType::class,
            'uuid'             => UuidType::class,
            'ip_address'       => IpAddressType::class,
            'phone'            => PhoneType::class,
            'encrypted_string' => EncryptedStringType::class,
            'enum_string'      => EnumStringType::class,
        ]);
    }
}
