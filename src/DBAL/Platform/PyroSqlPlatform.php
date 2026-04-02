<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Platform;

final class PyroSqlPlatform extends PostgresPlatform
{
    public function getName(): string
    {
        return 'pyrosql';
    }
}
