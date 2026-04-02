<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;
use InvalidArgumentException;

final class UuidType extends WeaverType
{
    public function getName(): string
    {
        return 'uuid';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'CHAR(36)';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $uuid = (string) $value;

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new InvalidArgumentException(
                sprintf('Value "%s" is not a valid UUID.', $uuid),
            );
        }

        return $uuid;
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
