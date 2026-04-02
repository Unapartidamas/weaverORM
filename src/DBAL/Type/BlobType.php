<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

final class BlobType extends Type
{
    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        return $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        return $value;
    }

    public function getName(): string
    {
        return 'blob';
    }
}
