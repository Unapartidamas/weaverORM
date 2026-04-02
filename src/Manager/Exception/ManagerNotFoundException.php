<?php

declare(strict_types=1);

namespace Weaver\ORM\Manager\Exception;

final class ManagerNotFoundException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("EntityWorkspace '{$name}' not found in ManagerRegistry.");
    }
}
