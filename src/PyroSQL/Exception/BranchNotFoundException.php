<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Exception;

final class BranchNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("PyroSQL branch '{$name}' does not exist.");
    }
}
