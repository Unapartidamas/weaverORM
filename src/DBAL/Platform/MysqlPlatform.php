<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Platform;

use Weaver\ORM\DBAL\Platform;

final class MysqlPlatform extends Platform
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getDateTimeFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function supportsSavepoints(): bool
    {
        return true;
    }
}
