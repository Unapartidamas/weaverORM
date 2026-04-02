<?php

declare(strict_types=1);

namespace Weaver\ORM\Pagination;

use Weaver\ORM\Collection\EntityCollection;

final readonly class Page
{
    public function __construct(
        public readonly EntityCollection $items,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $lastPage,
        public readonly bool $hasMorePages,
    ) {}

    public static function create(
        EntityCollection $items,
        int $total,
        int $currentPage,
        int $perPage,
    ): self {
        $lastPage = max(1, (int) ceil($total / $perPage));

        return new self(
            items: $items,
            total: $total,
            currentPage: $currentPage,
            perPage: $perPage,
            lastPage: $lastPage,
            hasMorePages: $currentPage < $lastPage,
        );
    }

    public function toArray(): array
    {
        return [
            'data'          => $this->items->jsonSerialize(),
            'total'         => $this->total,
            'current_page'  => $this->currentPage,
            'per_page'      => $this->perPage,
            'last_page'     => $this->lastPage,
            'has_more_pages' => $this->hasMorePages,
        ];
    }
}
