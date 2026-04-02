<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use BackedEnum;
use Weaver\ORM\DBAL\Platform;
use UnitEnum;

final class EnumStringType extends WeaverType
{
    public function getName(): string
    {
        return 'enum_string';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'VARCHAR(100)';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return (string) $value;
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
