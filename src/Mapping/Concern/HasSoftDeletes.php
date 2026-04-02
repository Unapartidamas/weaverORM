<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping\Concern;

use Weaver\ORM\Mapping\ColumnDefinition;

trait HasSoftDeletes
{
    public function getSoftDeleteColumns(): array
    {
        return [
            new ColumnDefinition(
                column: 'deleted_at',
                property: 'deletedAt',
                type: 'datetime_immutable',
                nullable: true,
            ),
        ];
    }
}
