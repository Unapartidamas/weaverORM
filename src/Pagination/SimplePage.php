<?php

declare(strict_types=1);

namespace Weaver\ORM\Pagination;

use Weaver\ORM\Collection\EntityCollection;

final readonly class SimplePage
{
    public function __construct(
        public readonly EntityCollection $items,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly bool $hasMorePages,
    ) {}

    public function toArray(): array
    {
        return [
            'data'           => $this->items->jsonSerialize(),
            'current_page'   => $this->currentPage,
            'per_page'       => $this->perPage,
            'has_more_pages' => $this->hasMorePages,
        ];
    }
}
