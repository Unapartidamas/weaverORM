<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Vector;

use Weaver\ORM\Mapping\ColumnDefinition;

final readonly class VectorColumnDefinition extends ColumnDefinition
{

    public function __construct(
        string $column,
        string $property,
        public readonly int $dimensions,
        bool $nullable = true,
        ?string $comment = null,
    ) {
        parent::__construct(
            column:   $column,
            property: $property,
            type:     'vector',
            nullable: $nullable,
            comment:  $comment,
        );
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
