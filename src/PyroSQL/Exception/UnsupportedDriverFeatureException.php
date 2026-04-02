<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Exception;

final class UnsupportedDriverFeatureException extends \RuntimeException
{
    public static function forFeature(string $feature): self
    {
        return new self(
            "PyroSQL feature '{$feature}' is not available on this connection. "
            . "Ensure you are connected to PyroSQL, not standard PostgreSQL."
        );
    }
}
