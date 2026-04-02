<?php

declare(strict_types=1);

namespace Weaver\ORM\Pagination;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Query\EntityQueryBuilder;

final class Paginator
{

    public function paginate(EntityQueryBuilder $qb, int $page = 1, int $perPage = 15): Page
    {

        $total = (clone $qb)->count();

        $items = (clone $qb)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return Page::create($items, $total, $page, $perPage);
    }

    public function simplePaginate(EntityQueryBuilder $qb, int $page = 1, int $perPage = 15): SimplePage
    {
        $items = (clone $qb)
            ->limit($perPage + 1)
            ->offset(($page - 1) * $perPage)
            ->get();

        $hasMore = $items->count() > $perPage;

        if ($hasMore) {

            $items = new EntityCollection(array_slice($items->toArray(), 0, $perPage));
        }

        return new SimplePage(
            items: $items,
            currentPage: $page,
            perPage: $perPage,
            hasMorePages: $hasMore,
        );
    }

    public function cursorPaginate(
        EntityQueryBuilder $qb,
        int $perPage = 15,
        string $cursorColumn = 'id',
        ?string $cursor = null,
    ): CursorPage {
        if ($cursor !== null) {
            $decodedCursor = base64_decode($cursor, strict: true);
            if ($decodedCursor !== false) {
                $qb->where($cursorColumn, '>', $decodedCursor);
            }
        }

        $items = (clone $qb)
            ->orderBy($cursorColumn)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $items->count() > $perPage;
        $nextCursor = null;

        if ($hasMore) {
            $itemsArray = array_slice($items->toArray(), 0, $perPage);
            $items = new EntityCollection($itemsArray);
            $lastItem = end($itemsArray);
            if ($lastItem !== false) {
                $nextCursor = base64_encode((string) ($lastItem->$cursorColumn ?? ''));
            }
        }

        return new CursorPage(
            items: $items,
            nextCursor: $nextCursor,
            prevCursor: null,
            hasMorePages: $hasMore,
        );
    }
}
