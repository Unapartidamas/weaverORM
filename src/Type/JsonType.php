<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;

final class JsonType extends WeaverType
{
    public function getName(): string
    {
        return 'json';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        $name = $platform->getName();
        if ($name === 'postgresql' || $name === 'mysql') {
            return 'JSON';
        }

        return 'TEXT';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value);
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode((string) $value, true);
    }
}
