---
id: querying
title: क्वेरी बिल्डर
---

`EntityQueryBuilder` is Weaver ORM's fluent query API. It wraps Doctrine DBAL with full entity awareness: results are automatically hydrated into entity objects, global scopes are applied, and soft-delete filters are managed transparently.

---

## Getting a QueryBuilder

Obtain a builder from a repository via `query()`, or from `EntityWorkspace` via `createQueryBuilder()`:

```php
<?php

// From a repository (recommended)
$users = $this->userRepository
    ->query()
    ->where('is_active', true)
    ->get();

// From the workspace
use Weaver\ORM\EntityWorkspace;
use App\Entity\Post;

$posts = $this->workspace
    ->createQueryBuilder(Post::class)
    ->where('status', 'published')
    ->get();
```

---

## SELECT clauses

### `select(...$columns)`

Replace the SELECT list. Calling it again replaces the previous selection.

```php
<?php

$users = $this->userRepository
    ->query()
    ->select('id', 'name', 'email')
    ->get();
```

### `addSelect(...$columns)`

Append columns to the current SELECT list without replacing it.

```php
<?php

$qb = $this->productRepository->query()->select('id', 'name', 'price');

if ($this->isGranted('ROLE_MANAGER')) {
    $qb->addSelect('cost_price', 'supplier_id');
}

$products = $qb->get();
```

### `selectRaw($expression, $bindings = [])`

Add a raw SQL expression to the SELECT list.

```php
<?php

$orders = $this->orderRepository
    ->query()
    ->select('id', 'customer_id', 'total')
    ->selectRaw('DATEDIFF(NOW(), created_at) AS age_days')
    ->where('status', 'pending')
    ->get();
```

---

## WHERE clauses

All `where*()` methods combine conditions with `AND` by default. Use `orWhere*()` variants to combine with `OR`.

### `where($column, $value)` — equality

```php
<?php

$user = $this->userRepository
    ->query()
    ->where('email', 'alice@example.com')
    ->first();
```

### `where($column, $operator, $value)` — with operator

Supported operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`.

```php
<?php

$expensive = $this->productRepository
    ->query()
    ->where('price', '>', 100)
    ->where('stock', '>', 0)
    ->get();
```

### `orWhere($column, $value)`

```php
<?php

$results = $this->userRepository
    ->query()
    ->where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->get();
```

### `whereIn($column, $values)`

```php
<?php

$users = $this->userRepository
    ->query()
    ->whereIn('status', ['active', 'trial'])
    ->get();
```

### `whereNotIn($column, $values)`

```php
<?php

$posts = $this->postRepository
    ->query()
    ->whereNotIn('status', ['draft', 'archived'])
    ->get();
```

### `whereNull($column)` / `whereNotNull($column)`

```php
<?php

// Users who have never logged in
$neverLoggedIn = $this->userRepository
    ->query()
    ->whereNull('last_login_at')
    ->get();

// Users with a verified email
$verified = $this->userRepository
    ->query()
    ->whereNotNull('email_verified_at')
    ->get();
```

### `whereBetween($column, $min, $max)`

```php
<?php

$orders = $this->orderRepository
    ->query()
    ->whereBetween('total', 50, 500)
    ->get();
```

### `whereRaw($sql, $bindings = [])`

```php
<?php

$active = $this->subscriptionRepository
    ->query()
    ->whereRaw('expires_at > NOW() AND cancelled_at IS NULL')
    ->get();

// Always use bindings to avoid SQL injection
$results = $this->repository
    ->query()
    ->whereRaw('YEAR(created_at) = ?', [2024])
    ->get();
```

---

## ORDER BY

### `orderBy($column, $direction = 'ASC')`

```php
<?php

$users = $this->userRepository
    ->query()
    ->orderBy('created_at', 'DESC')
    ->get();
```

### `orderByDesc($column)`

Shorthand for `orderBy($column, 'DESC')`.

```php
<?php

$latest = $this->postRepository
    ->query()
    ->where('status', 'published')
    ->orderByDesc('published_at')
    ->limit(10)
    ->get();
```

### `orderByRaw($expression)`

```php
<?php

$products = $this->productRepository
    ->query()
    ->orderByRaw('FIELD(status, "featured", "active", "inactive")')
    ->get();
```

---

## LIMIT and OFFSET

### `limit($n)` / `offset($n)`

```php
<?php

$page3 = $this->productRepository
    ->query()
    ->where('is_active', true)
    ->orderBy('name')
    ->limit(20)
    ->offset(40)
    ->get();
```

---

## Eager loading

### `with(...$relations)`

Load related entities in the same query (or a batched second query), avoiding N+1 problems.

```php
<?php

$posts = $this->postRepository
    ->query()
    ->where('status', 'published')
    ->with('author', 'tags', 'comments')
    ->orderByDesc('published_at')
    ->get();

// Relations are already loaded — no extra queries
foreach ($posts as $post) {
    echo $post->getAuthor()->getName();
}
```

Nested relations use dot notation:

```php
<?php

$orders = $this->orderRepository
    ->query()
    ->with('customer', 'items.product')
    ->get();
```

---

## Soft delete helpers

When an entity uses the `#[SoftDelete]` attribute, a global scope automatically filters out soft-deleted rows. Use these methods to override the default behaviour.

### `withTrashed()` — include soft-deleted rows

```php
<?php

$allPosts = $this->postRepository
    ->query()
    ->withTrashed()
    ->get();
```

### `onlyTrashed()` — return only soft-deleted rows

```php
<?php

$deleted = $this->postRepository
    ->query()
    ->onlyTrashed()
    ->orderByDesc('deleted_at')
    ->get();
```

### `withoutTrashed()` — explicitly exclude soft-deleted rows

Useful inside a `withTrashed()` sub-scope to restore default filtering:

```php
<?php

$active = $this->postRepository
    ->query()
    ->withoutTrashed()
    ->get();
```

---

## SQL comments

### `comment($text)`

Appends a SQL comment to the generated query. Useful for identifying slow queries in the database slow-query log.

```php
<?php

$results = $this->reportRepository
    ->query()
    ->comment('monthly-revenue-report')
    ->where('month', $month)
    ->get();
// Generates: SELECT * FROM reports WHERE month = ? /* monthly-revenue-report */
```

---

## Scopes

Scopes are reusable query constraints. Weaver ORM supports **global scopes** (applied to every query) and **local scopes** (applied on demand).

### Global scopes

Implement `ScopeInterface` and register it on the mapper. The scope is applied to every query for that entity automatically.

```php
<?php

namespace App\Scope;

use Weaver\ORM\Mapping\MapperInterface;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Scope\ScopeInterface;

final class ActiveScope implements ScopeInterface
{
    public function apply(EntityQueryBuilder $query, MapperInterface $mapper): void
    {
        $query->where('is_active', true);
    }
}
```

Register on the mapper:

```php
<?php

use App\Scope\ActiveScope;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\Attributes\GlobalScope;

#[GlobalScope(ActiveScope::class)]
class UserMapper extends AbstractMapper
{
    // ...
}
```

Remove a global scope for a specific query:

```php
<?php

$allUsers = $this->userRepository
    ->query()
    ->withoutScope(ActiveScope::class)
    ->get();
```

### Local scopes

Define local scope methods in the repository by prefixing the method name with `scope`:

```php
<?php

namespace App\Repository;

use Weaver\ORM\Repository\EntityRepository;
use Weaver\ORM\Query\EntityQueryBuilder;

class OrderRepository extends EntityRepository
{
    public function scopePending(EntityQueryBuilder $query): EntityQueryBuilder
    {
        return $query->where('status', 'pending');
    }

    public function scopeHighValue(EntityQueryBuilder $query, int $threshold = 1000): EntityQueryBuilder
    {
        return $query->where('total', '>=', $threshold);
    }
}

// Usage:
$pendingHighValue = $this->orderRepository
    ->query()
    ->pending()
    ->highValue(500)
    ->get();
```

### TenantScope example

A common pattern for multi-tenant applications:

```php
<?php

namespace App\Scope;

use App\Service\TenantContext;
use Weaver\ORM\Mapping\MapperInterface;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Scope\ScopeInterface;

final class TenantScope implements ScopeInterface
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function apply(EntityQueryBuilder $query, MapperInterface $mapper): void
    {
        $query->where('tenant_id', $this->context->currentTenantId());
    }
}
```

---

## Result retrieval

### `get()` — all results as EntityCollection

```php
<?php

$products = $this->productRepository
    ->query()
    ->where('is_active', true)
    ->get(); // EntityCollection<Product>
```

### `first()` — first result or null

```php
<?php

$latest = $this->postRepository
    ->query()
    ->orderByDesc('created_at')
    ->first(); // ?Post
```

### `firstOrFail()` — first result or exception

```php
<?php

$post = $this->postRepository
    ->query()
    ->where('slug', $slug)
    ->firstOrFail(); // throws EntityNotFoundException if not found
```

### `count()` — row count

```php
<?php

$total = $this->userRepository
    ->query()
    ->where('status', 'active')
    ->count(); // int
```

### `exists()` — check existence without loading entities

```php
<?php

$alreadyRegistered = $this->userRepository
    ->query()
    ->where('email', $email)
    ->exists(); // bool
```

---

## Pagination

### `paginate($page, $perPage)` — returns a `Page` object

```php
<?php

$page = $this->productRepository
    ->query()
    ->where('is_active', true)
    ->orderBy('name')
    ->paginate(page: 2, perPage: 20);

// $page->items()       — EntityCollection for the current page
// $page->total()       — total matching rows
// $page->currentPage() — current page number
// $page->lastPage()    — last page number
// $page->hasMore()     — whether a next page exists
```

See the [Pagination](pagination) page for the full `Page` API and cursor-based pagination.
