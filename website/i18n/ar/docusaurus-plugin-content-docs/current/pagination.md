---
id: pagination
title: التصفح
---

Weaver ORM provides three paginator types for different use cases: `Page` (standard offset pagination), `SimplePage` (lightweight, no total count), and `CursorPage` (cursor-based, ideal for infinite scroll and large datasets).

---

## Standard pagination — `paginate()`

Call `paginate()` on any `EntityQueryBuilder` to execute a count query and a data query simultaneously, returning a `Page` object.

```php
<?php

$page = $this->productRepository
    ->query()
    ->where('is_active', true)
    ->orderBy('name')
    ->paginate(page: 2, perPage: 20);
```

### `Page` object

| Method | Return type | Description |
|---|---|---|
| `items()` | `EntityCollection` | Entities for the current page |
| `total()` | `int` | Total number of matching rows |
| `currentPage()` | `int` | The requested page number |
| `lastPage()` | `int` | The highest available page number |
| `perPage()` | `int` | Page size |
| `from()` | `int` | Index of the first item on this page (1-based) |
| `to()` | `int` | Index of the last item on this page (1-based) |
| `hasMore()` | `bool` | Whether a next page exists |
| `hasPrevious()` | `bool` | Whether a previous page exists |

```php
<?php

$page = $this->userRepository
    ->query()
    ->where('role', 'subscriber')
    ->orderBy('created_at', 'DESC')
    ->paginate(page: $request->query->getInt('page', 1), perPage: 25);

// Access data
foreach ($page->items() as $user) {
    echo $user->getName();
}

echo "Page {$page->currentPage()} of {$page->lastPage()}";
echo "Showing {$page->from()}–{$page->to()} of {$page->total()} users";

if ($page->hasMore()) {
    $nextUrl = $this->generateUrl('users_list', ['page' => $page->currentPage() + 1]);
}
```

---

## Simple pagination — `SimplePage`

`SimplePage` skips the `COUNT(*)` query and only checks whether a next page exists by fetching `perPage + 1` rows. Use it when you do not need to show the total count or the last page number (e.g., "Next / Previous" navigation).

```php
<?php

$page = $this->postRepository
    ->query()
    ->where('status', 'published')
    ->orderByDesc('published_at')
    ->simplePaginate(page: $currentPage, perPage: 15);

// Returns SimplePage:
$page->items();       // EntityCollection for this page
$page->currentPage(); // int
$page->hasMore();     // bool — is there a next page?
```

`SimplePage` does **not** expose `total()` or `lastPage()`. It is significantly faster than standard pagination on large tables because it avoids the full-table count.

---

## Cursor pagination — `CursorPage`

Cursor-based pagination is stateless and consistent: instead of using an `OFFSET`, it uses an opaque cursor token representing the last seen row. This avoids the performance degradation of large offsets and produces stable results even when rows are inserted or deleted between pages.

```php
<?php

// First page — no cursor
$page = $this->eventRepository
    ->query()
    ->orderByDesc('id')
    ->cursorPaginate(perPage: 50, cursor: null);

$items  = $page->items();         // EntityCollection
$cursor = $page->nextCursor();    // opaque string token, or null if no more pages
$hasMore = $page->hasMore();

// Subsequent page — pass the cursor from the previous page
$nextPage = $this->eventRepository
    ->query()
    ->orderByDesc('id')
    ->cursorPaginate(perPage: 50, cursor: $request->query->get('cursor'));
```

### `CursorPage` object

| Method | Return type | Description |
|---|---|---|
| `items()` | `EntityCollection` | Entities for this cursor window |
| `nextCursor()` | `?string` | Opaque token to pass for the next page, `null` when on the last page |
| `previousCursor()` | `?string` | Opaque token to pass for the previous page |
| `hasMore()` | `bool` | Whether a next page exists |

The cursor value is a base64-encoded JSON payload containing the sort column values of the last row. It is signed to prevent tampering.

---

## Symfony response integration

### JSON API response

```php
<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    #[Route('/api/products', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = $this->products
            ->query()
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(
                page:    $request->query->getInt('page', 1),
                perPage: $request->query->getInt('per_page', 20),
            );

        return $this->json([
            'data'  => $page->items()->toArray(),
            'meta'  => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'from'         => $page->from(),
                'to'           => $page->to(),
            ],
            'links' => [
                'next' => $page->hasMore()
                    ? $this->generateUrl('api_products', ['page' => $page->currentPage() + 1])
                    : null,
                'prev' => $page->hasPrevious()
                    ? $this->generateUrl('api_products', ['page' => $page->currentPage() - 1])
                    : null,
            ],
        ]);
    }
}
```

### Cursor-based API

```php
<?php

#[Route('/api/events', methods: ['GET'])]
public function events(Request $request): JsonResponse
{
    $page = $this->eventRepository
        ->query()
        ->orderByDesc('id')
        ->cursorPaginate(
            perPage: 100,
            cursor:  $request->query->get('cursor'),
        );

    return $this->json([
        'data'        => $page->items()->toArray(),
        'next_cursor' => $page->nextCursor(),
        'has_more'    => $page->hasMore(),
    ]);
}
```

### Twig template example

```php
<?php

// In your controller:
return $this->render('products/index.html.twig', [
    'page' => $this->products->query()->paginate($request->query->getInt('p', 1), 15),
]);
```

```twig
{# templates/products/index.html.twig #}
{% for product in page.items() %}
    <div>{{ product.name }}</div>
{% endfor %}

<nav>
    {% if page.hasPrevious() %}
        <a href="{{ path('products', {p: page.currentPage - 1}) }}">Previous</a>
    {% endif %}

    Page {{ page.currentPage }} of {{ page.lastPage }}

    {% if page.hasMore() %}
        <a href="{{ path('products', {p: page.currentPage + 1}) }}">Next</a>
    {% endif %}
</nav>
```

---

## In-memory pagination on EntityCollection

If you already have a fully loaded `EntityCollection` and want to paginate it in memory (without an additional database query), use the `paginate()` method directly on the collection:

```php
<?php

$all  = $this->categoryRepository->findAll(); // EntityCollection<Category>
$page = $all->paginate(page: 2, perPage: 10);  // Page object
```

This is useful for small, cacheable datasets where loading all rows once and paging in memory is more efficient than repeated offset queries.
