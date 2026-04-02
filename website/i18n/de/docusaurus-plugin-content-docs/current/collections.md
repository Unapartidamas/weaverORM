---
id: collections
title: Entity-Collections
---

`Weaver\ORM\Collection\EntityCollection` is a typed, immutable-by-default collection returned by repositories and query builders. Every method that would mutate the collection returns a **new** `EntityCollection` instance, so the original is never modified. This makes collections safe to pass around without defensive copying.

```php
<?php

use Weaver\ORM\Collection\EntityCollection;
use App\Entity\Product;

// Collections are usually produced by repositories:
/** @var EntityCollection<Product> $products */
$products = $this->productRepository->findAll();

// You can also construct one manually:
$products = new EntityCollection([$product1, $product2, $product3]);
```

---

## Iteration and access

### `count(): int`

Returns the number of entities in the collection. `EntityCollection` also implements `Countable`, so `count($collection)` works too.

```php
<?php

echo $products->count(); // 42
echo count($products);   // 42
```

### `isEmpty(): bool` / `isNotEmpty(): bool`

```php
<?php

if ($products->isEmpty()) {
    throw new \RuntimeException('No products found.');
}

if ($products->isNotEmpty()) {
    $this->sendStockReport($products);
}
```

### `first(): ?TEntity` / `last(): ?TEntity`

Returns the first or last entity, or `null` if the collection is empty.

```php
<?php

$cheapest   = $products->sortBy('price')->first();
$mostPricey = $products->sortBy('price')->last();
```

### `firstOrFail(): TEntity`

Returns the first entity or throws `EntityNotFoundException` if the collection is empty.

```php
<?php

$featured = $this->featuredRepo->findByCategory($category)->firstOrFail();
```

### `all(): array` / `toArray(): array`

- `all()` — returns a plain PHP array of entity objects (copy, not a reference).
- `toArray()` — serialises each entity to an associative array by calling `toArray()` on the entity (or reading public properties via reflection).

```php
<?php

$entities = $products->all();       // array<int, Product>
$data     = $products->toArray();   // array<int, array<string, mixed>>
```

### `toJson(int $flags = 0): string`

Serialises the collection to a JSON string.

```php
<?php

return new JsonResponse(
    json_decode($products->toJson(JSON_PRETTY_PRINT), true)
);
```

---

## Filtering

### `filter(callable $callback): static`

Returns a new collection containing only the entities for which the callback returns `true`.

```php
<?php

$inStock = $products->filter(fn(Product $p) => $p->getStock() > 0);

$expensiveActive = $products->filter(
    fn(Product $p) => $p->isActive() && $p->getPrice() > 100
);
```

---

## Transformation

### `map(callable $callback): array`

Transforms each entity with the callback and returns a plain PHP array.

```php
<?php

$names = $products->map(fn(Product $p) => $p->getName());
// ['Widget', 'Gadget', 'Doohickey']

$dtos = $products->map(fn(Product $p) => ProductDto::fromEntity($p));
```

### `pluck(string $property): array`

Extracts a single property from every entity and returns a flat array.

```php
<?php

$ids    = $products->pluck('id');    // [1, 2, 3, ...]
$emails = $users->pluck('email');    // ['a@example.com', ...]
```

### `groupBy(string|callable $key): array`

Groups entities by a property name or callable. Returns an associative array where the keys are the group values and the values are `EntityCollection` instances.

```php
<?php

$byStatus = $orders->groupBy('status');
// [
//   'pending'   => EntityCollection<Order>,
//   'shipped'   => EntityCollection<Order>,
//   'delivered' => EntityCollection<Order>,
// ]

// Group by arbitrary logic:
$byDecade = $users->groupBy(fn(User $u) => (int) ($u->getAge() / 10) * 10);
```

### `sortBy(string|callable $key, string $direction = 'asc'): static`

Returns a new sorted collection without modifying the original. Accepts a property name or a callback returning the sort value.

```php
<?php

$byPrice  = $products->sortBy('price');
$byPriceDesc = $products->sortBy('price', 'desc');

// Sort by computed value:
$byRating = $products->sortBy(fn(Product $p) => $p->getAverageRating());
```

---

## Lookup

### `contains(object $entity): bool`

Returns `true` if the exact object (by identity) is in the collection.

```php
<?php

if ($cart->getItems()->contains($product)) {
    throw new \DomainException('Product already in cart.');
}
```

### `has(callable $callback): bool`

Returns `true` if at least one entity satisfies the callback (similar to `array_filter` returning a non-empty result).

```php
<?php

$hasOutOfStock = $products->has(fn(Product $p) => $p->getStock() === 0);
```

---

## Set operations

### `unique(string|callable $key): static`

Returns a new collection with duplicate values removed, based on a property name or callback.

```php
<?php

$uniqueAuthors = $posts->unique('authorId');
```

### `merge(EntityCollection $other): static`

Returns a new collection combining both collections.

```php
<?php

$all = $published->merge($drafts);
```

### `diff(EntityCollection $other): static`

Returns entities present in the first collection but not the second (by object identity).

```php
<?php

$removed = $originalItems->diff($updatedItems);
```

---

## Aggregates

### `sum(string|callable $key): int|float`

```php
<?php

$totalRevenue = $orders->sum('total');
$totalWeight  = $items->sum(fn($item) => $item->product->weight * $item->quantity);
```

### `avg(string|callable $key): float`

```php
<?php

$averagePrice = $products->avg('price');
```

### `min(string $key): mixed` / `max(string $key): mixed`

```php
<?php

$cheapest  = $products->min('price');
$mostPricy = $products->max('price');
```

---

## Relation collections

When an entity declares a one-to-many or many-to-many relationship, the loaded related entities are also wrapped in an `EntityCollection`:

```php
<?php

$order = $this->orderRepo->findOrFail($id);

// $order->getItems() returns EntityCollection<OrderItem>
$heavyItems = $order->getItems()
    ->filter(fn(OrderItem $i) => $i->getWeight() > 5.0);

$totalWeight = $order->getItems()->sum('weight');
```

Relation collections support the same full API as top-level collections.
