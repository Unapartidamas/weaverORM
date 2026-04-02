<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

final class HydrationException extends \RuntimeException
{

    public static function invalidType(string $property, string $expected, mixed $actual): self
    {
        $actualType = get_debug_type($actual);

        return new self(
            sprintf(
                'Hydration failed for property "%s": expected type "%s", got "%s".',
                $property,
                $expected,
                $actualType,
            )
        );
    }

    public static function missingColumn(string $column, string $entityClass): self
    {
        return new self(
            sprintf(
                'Hydration failed for entity "%s": column "%s" is missing from the result row. '
                . 'Ensure the column is included in the SELECT clause.',
                $entityClass,
                $column,
            )
        );
    }
}
