---
id: repositories
title: リポジトリ
---

Repositories are the primary way to read entities from the database. Every entity has a corresponding repository that encapsulates all query logic for that entity, keeping controllers and services free of raw SQL or query-builder calls.

---

## EntityRepository base class

All repositories extend `Weaver\ORM\Repository\EntityRepository`. The base class provides standard read methods out of the box, with full type-safe return values via PHP generics in docblocks.

```php
<?php

namespace App\Repository;

use App\Entity\User;
use Weaver\ORM\Repository\EntityRepository;

/**
 * @extends EntityRepository<User>
 */
class UserRepository extends EntityRepository {}
```

---

## Built-in read methods

### `find($id)` — find by primary key

Returns the entity or `null` if no row with that ID exists.

```php
<?php

$user = $this->userRepository->find(42);

if ($user === null) {
    // not found
}
```

The identity map is consulted first, so loading the same ID twice within a request returns the same PHP object without hitting the database a second time.

### `findOrFail($id)` — find or throw

Returns the entity or throws `Weaver\ORM\Exception\EntityNotFoundException` if not found. Useful in controllers and command handlers where a missing entity is an error condition.

```php
<?php

$user = $this->userRepository->findOrFail($id);
// guaranteed to be a User — exception thrown otherwise
```

### `findBy(array $criteria, array $orderBy = [], ?int $limit = null, ?int $offset = null)` — find by criteria

Loads all entities matching the given field-value pairs. Optionally sort, limit, and offset the results.

```php
<?php

// All active admins ordered by name
$admins = $this->userRepository->findBy(
    criteria: ['role' => 'admin', 'is_active' => true],
    orderBy:  ['name' => 'ASC'],
);

// Paginate manually
$page2 = $this->userRepository->findBy(
    criteria: ['status' => 'pending'],
    orderBy:  ['created_at' => 'DESC'],
    limit:    20,
    offset:   20,
);
```

### `findAll()` — load every entity

Returns all rows for the entity's table as an `EntityCollection`. Use with care on large tables.

```php
<?php

$countries = $this->countryRepository->findAll();

foreach ($countries as $country) {
    echo $country->getName();
}
```

---

## Custom repositories

Define custom query methods by extending `EntityRepository` and using the `query()` method to obtain an `EntityQueryBuilder` pre-scoped to the entity's table.

```php
<?php

namespace App\Repository;

use App\Entity\Post;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Repository\EntityRepository;

/**
 * @extends EntityRepository<Post>
 */
class PostRepository extends EntityRepository
{
    /**
     * @return EntityCollection<Post>
     */
    public function findPublishedByAuthor(int $authorId): EntityCollection
    {
        return $this->query()
            ->where('author_id', $authorId)
            ->where('status', 'published')
            ->orderBy('published_at', 'DESC')
            ->get();
    }

    public function findFeaturedOnHomepage(): EntityCollection
    {
        return $this->query()
            ->where('is_featured', true)
            ->where('status', 'published')
            ->orderBy('featured_order')
            ->limit(5)
            ->get();
    }

    public function countDraftsByAuthor(int $authorId): int
    {
        return $this->query()
            ->where('author_id', $authorId)
            ->where('status', 'draft')
            ->count();
    }
}
```

---

## `query()` — get an EntityQueryBuilder

`query()` is the entry point to the fluent query API. It returns an `EntityQueryBuilder` already configured with:

- The correct `FROM` table (derived from the entity mapper).
- The entity class for hydration.
- All registered global scopes.

```php
<?php

public function findRecentlyUpdated(int $days = 7): EntityCollection
{
    return $this->query()
        ->where('updated_at', '>=', new \DateTimeImmutable("-{$days} days"))
        ->orderBy('updated_at', 'DESC')
        ->get();
}
```

See the [Query Builder](querying) page for the full list of available methods.

---

## Repository registration

Repositories are registered as Symfony services. With autowiring enabled, declare the repository as a constructor dependency anywhere in your application.

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Repository\:
        resource: '../src/Repository/'
```

```php
<?php

namespace App\Controller;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PostController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
    ) {}

    #[Route('/posts', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(
            $this->posts->findPublishedByAuthor($this->getUser()->getId())
        );
    }
}
```

Each repository must declare which entity class and mapper it belongs to. When extending `EntityRepository` you can do this via a class constant or by overriding `getEntityClass()`:

```php
<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Mapping\InvoiceMapper;
use Weaver\ORM\Repository\EntityRepository;

/**
 * @extends EntityRepository<Invoice>
 */
class InvoiceRepository extends EntityRepository
{
    protected string $entityClass  = Invoice::class;
    protected string $mapperClass  = InvoiceMapper::class;
}
```

---

## CachingRepository — optional caching layer

Wrap any repository with `CachingRepository` to cache individual lookups (by primary key) using any PSR-6 cache pool.

```php
<?php

namespace App\Repository;

use App\Entity\Country;
use Weaver\ORM\Repository\CachingRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @extends CachingRepository<Country>
 */
class CountryRepository extends CachingRepository
{
    protected int $ttl = 3600; // seconds

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct($cache);
    }
}
```

`CachingRepository` overrides `find()` and `findOrFail()` to check the cache before querying the database. Custom query methods defined in your subclass always bypass the cache unless you add caching logic explicitly.

```php
<?php

// First call: hits DB, stores in cache
$country = $this->countryRepository->find('DE');

// Subsequent calls within TTL: served from cache
$country = $this->countryRepository->find('DE');

// Manually invalidate
$this->countryRepository->invalidate('DE');
```
