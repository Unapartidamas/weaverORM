<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

final class BooleanType extends Type
{
    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value === 'f' || $value === 'false' || $value === '0' || $value === 0 || $value === false) {
            return false;
        }

        return (bool) $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        return $value === null ? null : (bool) $value;
    }

    public function getName(): string
    {
        return 'boolean';
    }
}
