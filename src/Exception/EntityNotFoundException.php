<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

final class EntityNotFoundException extends \RuntimeException
{

    public static function forId(string $entityClass, mixed $id): self
    {
        $idDisplay = is_scalar($id) ? (string) $id : get_debug_type($id);

        return new self(
            sprintf(
                'Entity "%s" with id "%s" was not found.',
                $entityClass,
                $idDisplay,
            )
        );
    }

    public static function noResults(string $entityClass): self
    {
        return new self(
            sprintf(
                'Expected exactly one result for entity "%s", but the query returned no rows.',
                $entityClass,
            )
        );
    }

    public static function multipleResults(string $entityClass, int $count): self
    {
        return new self(
            sprintf(
                'Expected exactly one result for entity "%s", but the query returned %d rows.',
                $entityClass,
                $count,
            )
        );
    }
}
