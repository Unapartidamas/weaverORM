<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Type;

use Weaver\ORM\DBAL\Platform;

final class DateTimeType extends Type
{
    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($value);
        }

        $result = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormat(), (string) $value);

        if ($result === false) {
            $result = new \DateTimeImmutable((string) $value);
        }

        return $result;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($platform->getDateTimeFormat());
        }

        return $value;
    }

    public function getName(): string
    {
        return 'datetime';
    }
}
