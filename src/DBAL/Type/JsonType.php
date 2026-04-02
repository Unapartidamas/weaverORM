<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

final class JsonType extends Type
{
    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function getName(): string
    {
        return 'json';
    }
}
