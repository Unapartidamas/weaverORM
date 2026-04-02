<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Criteria;

final readonly class Expression
{
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value,
        public string $boolean = 'AND',
    ) {
    }
}
