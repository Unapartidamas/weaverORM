<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

final class FloatType extends Type
{
    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        return $value === null ? null : (float) $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        return $value === null ? null : (float) $value;
    }

    public function getName(): string
    {
        return 'float';
    }
}
