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

        $str = (string) $value;

        // PyroSQL may return timestamps as microseconds (e.g. 1775205186000000)
        if (ctype_digit($str) && strlen($str) >= 13) {
            $seconds = (int) ($str / 1_000_000);
            return (new \DateTimeImmutable())->setTimestamp($seconds);
        }

        $result = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormat(), $str);

        if ($result === false) {
            $result = new \DateTimeImmutable($str);
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
