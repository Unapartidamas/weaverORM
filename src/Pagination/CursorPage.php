<?php

declare(strict_types=1);

namespace Weaver\ORM\Pagination;

use Weaver\ORM\Collection\EntityCollection;

final readonly class CursorPage
{

    public function __construct(
        public readonly EntityCollection $items,
        public readonly ?string $nextCursor,
        public readonly ?string $prevCursor,
        public readonly bool $hasMorePages,
    ) {}

    public function toArray(): array
    {
        return [
            'data'            => $this->items->jsonSerialize(),
            'next_cursor'     => $this->nextCursor,
            'prev_cursor'     => $this->prevCursor,
            'has_more_pages'  => $this->hasMorePages,
        ];
    }
}
