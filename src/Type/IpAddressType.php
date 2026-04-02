<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;
use InvalidArgumentException;

final class IpAddressType extends WeaverType
{
    public function getName(): string
    {
        return 'ip_address';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'VARCHAR(45)';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $ip = (string) $value;

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(
                sprintf('Value "%s" is not a valid IP address.', $ip),
            );
        }

        return $ip;
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
