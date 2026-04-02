<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;

final class UlidType extends WeaverType
{
    public function getName(): string
    {
        return 'ulid';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'CHAR(26)';
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
