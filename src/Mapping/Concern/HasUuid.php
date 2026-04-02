<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Concern;

use Weaver\ORM\Mapping\ColumnDefinition;

trait HasUuid
{

    public function getUuidColumn(string $column = 'id', string $property = 'id'): ColumnDefinition
    {
        return new ColumnDefinition(
            column: $column,
            property: $property,
            type: 'guid',
            primary: true,
            autoIncrement: false,
            length: 36,
        );
    }

    public function getUuidV7Column(string $column = 'id', string $property = 'id'): ColumnDefinition
    {
        return new ColumnDefinition(
            column: $column,
            property: $property,
            type: 'guid',
            primary: true,
            autoIncrement: false,
            length: 36,
            comment: 'uuid_v7',
        );
    }
}
