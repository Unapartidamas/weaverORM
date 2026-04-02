<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

abstract class Type
{
    private static array $types = [];

    abstract public function convertToPHPValue(mixed $value, Platform $platform): mixed;

    abstract public function convertToDatabaseValue(mixed $value, Platform $platform): mixed;

    abstract public function getName(): string;

    public static function getType(string $name): self
    {
        if (empty(self::$types)) {
            self::registerBuiltins();
        }

        return self::$types[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown type "%s".', $name));
    }

    public static function registerType(string $name, self $type): void
    {
        self::$types[$name] = $type;
    }

    public static function hasType(string $name): bool
    {
        if (empty(self::$types)) {
            self::registerBuiltins();
        }

        return isset(self::$types[$name]);
    }

    public static function registerBuiltins(): void
    {
        $builtins = [
            new StringType(),
            new IntegerType(),
            new FloatType(),
            new BooleanType(),
            new DateTimeType(),
            new DateType(),
            new JsonType(),
            new TextType(),
            new BlobType(),
        ];

        foreach ($builtins as $type) {
            self::$types[$type->getName()] = $type;
        }

        self::$types['datetime_immutable'] = self::$types['datetime'];
        self::$types['date_immutable'] = self::$types['date'];
    }

    public static function resetTypes(): void
    {
        self::$types = [];
    }
}
