<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;

final class PhoneType extends WeaverType
{
    public function getName(): string
    {
        return 'phone';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'VARCHAR(30)';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $phone = (string) $value;

        $prefix = str_starts_with($phone, '+') ? '+' : '';
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return $prefix . $digits;
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
