<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\DBAL\Type\Type;

abstract class WeaverType extends Type
{
    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'TEXT';
    }
}
