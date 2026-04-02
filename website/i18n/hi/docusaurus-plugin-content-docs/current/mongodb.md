---
id: mongodb
title: MongoDB सपोर्ट
---

Weaver ORM includes first-class support for MongoDB through a document-oriented abstraction layer. The API mirrors the relational ORM closely, so switching between SQL and MongoDB entities requires minimal mental overhead.

---

## AbstractDocumentMapper

All MongoDB document mappers extend `Weaver\ORM\MongoDB\AbstractDocumentMapper`. The mapper describes the collection name, field mappings, and embedded documents.

```php
<?php

namespace App\Mapping\Document;

use Weaver\ORM\MongoDB\AbstractDocumentMapper;
use Weaver\ORM\MongoDB\Attributes\DocumentCollection;
use Weaver\ORM\MongoDB\Attributes\Field;
use Weaver\ORM\MongoDB\Attributes\EmbedOne;
use Weaver\ORM\MongoDB\Attributes\EmbedMany;
use App\Document\Product;

#[DocumentCollection(name: 'products')]
class ProductMapper extends AbstractDocumentMapper
{
    public string $document = Product::class;

    #[Field(name: '_id', type: 'objectId')]
    public string $id;

    #[Field(type: 'string')]
    public string $name;

    #[Field(type: 'float')]
    public float $price;

    #[Field(type: 'string')]
    public string $status;

    #[Field(type: 'date')]
    public \DateTimeImmutable $createdAt;

    #[EmbedMany(document: 'App\Document\Tag')]
    public array $tags = [];

    #[EmbedOne(document: 'App\Document\Address')]
    public ?object $shippingAddress = null;
}
```

---

## `#[Document]` mapping

Annotate document classes with `#[Document]` to associate them with their mapper:

```php
<?php

namespace App\Document;

use Weaver\ORM\MongoDB\Attributes\Document;

#[Document(mapper: \App\Mapping\Document\ProductMapper::class)]
class Product
{
    public string $id;
    public string $name;
    public float  $price;
    public string $status;
    public \DateTimeImmutable $createdAt;
    public array $tags = [];
}
```

Embedded documents are plain PHP classes without the `#[Document]` attribute:

```php
<?php

namespace App\Document;

class Tag
{
    public function __construct(
        public string $name,
        public string $slug,
    ) {}
}

class Address
{
    public string $street;
    public string $city;
    public string $country;
    public string $postCode;
}
```

---

## DocumentQueryBuilder

`DocumentQueryBuilder` provides a fluent API for querying MongoDB collections. It maps closely to the relational `EntityQueryBuilder`:

```php
<?php

use App\Document\Product;

$products = $this->productRepository
    ->query()
    ->where('status', 'active')
    ->where('price', '<=', 100.0)
    ->orderBy('createdAt', 'DESC')
    ->limit(20)
    ->get();
```

### Filter operators

MongoDB-specific filter operators are supported via the `where()` method with operator syntax and via dedicated helpers:

```php
<?php

// Standard comparison operators
$query->where('price', '>=', 50);
$query->where('stock', '!=', 0);

// Array operators
$query->whereIn('status', ['active', 'featured']);
$query->whereNotIn('category', ['archived']);

// Existence check
$query->whereNull('deletedAt');
$query->whereNotNull('publishedAt');

// Regex (MongoDB-specific)
$query->whereRegex('name', '/^Widget/i');

// Embedded field queries using dot notation
$query->where('shippingAddress.country', 'DE');
$query->where('tags.name', 'sale');
```

### Array field queries

```php
<?php

// Match documents where the 'tags' array contains 'sale'
$onSale = $this->productRepository
    ->query()
    ->whereContains('tags.slug', 'sale')
    ->get();

// Match documents where the array size equals N
$query->whereArraySize('images', 3);
```

### Aggregation pipeline

For complex aggregation queries, access the raw collection through the document repository:

```php
<?php

$pipeline = [
    ['$match'  => ['status' => 'active']],
    ['$group'  => ['_id' => '$category', 'count' => ['$sum' => 1]]],
    ['$sort'   => ['count' => -1]],
    ['$limit'  => 10],
];

$results = $this->productRepository->aggregate($pipeline);
```

---

## DocumentPersistence

`Weaver\ORM\MongoDB\DocumentPersistence` handles insert, update, and delete operations for documents. It integrates with `EntityWorkspace` so the same `add()`, `push()`, and `delete()` calls work for both SQL entities and MongoDB documents.

```php
<?php

use App\Document\Product;
use App\Document\Tag;

$product = new Product();
$product->name   = 'Widget Pro';
$product->price  = 49.99;
$product->status = 'active';
$product->tags   = [new Tag('sale', 'sale'), new Tag('new', 'new')];

$this->workspace->add($product);
$this->workspace->push(); // inserts into MongoDB 'products' collection
```

Update operations track changed fields and issue targeted `$set` operations rather than replacing the entire document:

```php
<?php

$product = $this->productRepository->findOrFail($id);
$product->price  = 39.99;
$product->status = 'sale';

$this->workspace->push();
// Executes: db.products.updateOne({_id: ...}, {$set: {price: 39.99, status: 'sale'}})
```

---

## Multi-database support

Configure multiple MongoDB databases in `config/packages/weaver.yaml`:

```yaml
weaver:
    mongodb:
        connections:
            default:
                uri:      '%env(MONGODB_URI)%'
                database: '%env(MONGODB_DATABASE)%'
            analytics:
                uri:      '%env(ANALYTICS_MONGODB_URI)%'
                database: 'analytics'
```

Assign a connection to a mapper:

```php
<?php

use Weaver\ORM\MongoDB\Attributes\DocumentCollection;

#[DocumentCollection(name: 'events', connection: 'analytics')]
class EventMapper extends AbstractDocumentMapper
{
    // This mapper uses the 'analytics' connection
}
```

Repositories for mappers bound to a specific connection automatically use that connection for all queries and writes.

---

## Repository pattern for documents

Document repositories follow the same pattern as relational entity repositories:

```php
<?php

namespace App\Repository\Document;

use App\Document\Product;
use Weaver\ORM\MongoDB\DocumentRepository;

/**
 * @extends DocumentRepository<Product>
 */
class ProductDocumentRepository extends DocumentRepository
{
    public function findByCategoryAndPriceRange(
        string $category,
        float $minPrice,
        float $maxPrice,
    ): \Weaver\ORM\Collection\EntityCollection {
        return $this->query()
            ->where('category', $category)
            ->where('price', '>=', $minPrice)
            ->where('price', '<=', $maxPrice)
            ->where('status', 'active')
            ->orderBy('price')
            ->get();
    }

    public function findByTag(string $tagSlug): \Weaver\ORM\Collection\EntityCollection
    {
        return $this->query()
            ->whereContains('tags.slug', $tagSlug)
            ->orderByDesc('createdAt')
            ->get();
    }
}
```

Inject and use exactly as you would a relational repository:

```php
<?php

class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductDocumentRepository $products,
    ) {}

    #[Route('/products/tag/{slug}')]
    public function byTag(string $slug): JsonResponse
    {
        return $this->json(
            $this->products->findByTag($slug)->toArray()
        );
    }
}
```
