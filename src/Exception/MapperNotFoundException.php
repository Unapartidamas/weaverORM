<?php

declare(strict_types=1);

namespace Weaver\ORM\Exception;

final class MapperNotFoundException extends \RuntimeException
{

    public static function forEntity(string $entityClass): self
    {
        return new self(
            sprintf(
                'No mapper registered for entity class "%s". '
                . 'Make sure the mapper extends AbstractEntityMapper and is tagged with "weaver.mapper".',
                $entityClass,
            )
        );
    }

    public static function forTable(string $table): self
    {
        return new self(
            sprintf(
                'No mapper registered for table "%s". '
                . 'Make sure a mapper with getTableName() === "%s" is tagged with "weaver.mapper".',
                $table,
                $table,
            )
        );
    }
}
