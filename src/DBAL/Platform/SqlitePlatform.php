<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Platform;

use Weaver\ORM\DBAL\Platform;

final class SqlitePlatform extends Platform
{
    public function getName(): string
    {
        return 'sqlite';
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function getDateTimeFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function supportsReturning(): bool
    {
        return version_compare(\SQLite3::version()['versionString'] ?? '0', '3.35.0', '>=');
    }

    public function supportsSavepoints(): bool
    {
        return true;
    }
}
