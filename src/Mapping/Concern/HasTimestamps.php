<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Concern;

use Weaver\ORM\Mapping\ColumnDefinition;

trait HasTimestamps
{
    public function getTimestampColumns(): array
    {
        return [
            new ColumnDefinition(
                column: 'created_at',
                property: 'createdAt',
                type: 'datetime_immutable',
                nullable: false,
            ),
            new ColumnDefinition(
                column: 'updated_at',
                property: 'updatedAt',
                type: 'datetime_immutable',
                nullable: false,
            ),
        ];
    }
}
