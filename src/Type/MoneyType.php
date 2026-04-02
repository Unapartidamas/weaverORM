<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;

final class MoneyType extends WeaverType
{
    public function getName(): string
    {
        return 'money_cents';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'INTEGER';
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
