---
id: vectors
title: ベクトル検索
sidebar_label: Vector Search
---

Vector similarity search lets you find rows whose stored embedding vectors are closest to a query vector. This is the foundation of semantic search, recommendation systems, and AI-powered retrieval — where "closeness" is measured by cosine distance, Euclidean (L2) distance, or dot product, rather than exact string or numeric equality.

PyroSQL stores vectors in a native `VECTOR(n)` column type and accelerates similarity queries with HNSW and IVFFlat indexes. Weaver ORM exposes this through three classes: `VectorSearch`, `VectorIndex`, and `VectorColumnDefinition`.

---

## Declaring a vector column

Use `VectorColumnDefinition` in your entity mapper's `getColumns()` method instead of a plain `ColumnDefinition`. Set `dimensions` to match the output dimensionality of your embedding model.

```php
use Weaver\ORM\PyroSQL\Vector\VectorColumnDefinition;

// In your entity mapper:
protected function getColumns(): array
{
    return [
        new ColumnDefinition(column: 'id',      property: 'id',      type: 'integer'),
        new ColumnDefinition(column: 'title',   property: 'title',   type: 'string'),
        new ColumnDefinition(column: 'body',    property: 'body',    type: 'string'),
        // OpenAI text-embedding-3-small produces 1536-dimensional vectors
        new VectorColumnDefinition(
            column:     'embedding',
            property:   'embedding',
            dimensions: 1536,
            nullable:   true,
        ),
    ];
}
```

The ORM handles the `VECTOR(n)` column type transparently. `PyroSQL`-specific operations — nearest-neighbour search and index creation — are handled separately by `VectorSearch` and `VectorIndex`.

---

## `VectorSearch`

`VectorSearch` is a static utility class that builds the SQL fragments needed for k-nearest-neighbour queries. It does not hold state and cannot be instantiated.

### `VectorSearch::nearestNeighbors()`

```php
public static function nearestNeighbors(
    string $column,
    array  $vector,
    int    $k = 10,
    string $distanceOp = 'cosine',
): array
```

Returns an associative array with three keys:

| Key | Example | Purpose |
|-----|---------|---------|
| `orderBy` | `"embedding" <=> '[0.1,0.2,...]'` | Pass to `ORDER BY` |
| `limit` | `10` | The `k` value to pass to `LIMIT` |
| `distanceColumn` | `("embedding" <=> '[...]') AS _distance` | Add to `SELECT` to expose the score |

```php
use Weaver\ORM\PyroSQL\Vector\VectorSearch;

$queryVector = $openAi->embed('best noise-cancelling headphones');

$nn = VectorSearch::nearestNeighbors(
    column:     'embedding',
    vector:     $queryVector,
    k:          10,
    distanceOp: 'cosine',
);

$sql = "SELECT id, title, {$nn['distanceColumn']}
        FROM articles
        ORDER BY {$nn['orderBy']}
        LIMIT {$nn['limit']}";

$rows = $connection->fetchAllAssociative($sql);
```

Generates:
```sql
SELECT id, title, ("embedding" <=> '[0.021,-0.014,...]') AS _distance
FROM articles
ORDER BY "embedding" <=> '[0.021,-0.014,...]'
LIMIT 10
```

### Distance operators

| Name | SQL operator | Metric |
|------|-------------|--------|
| `cosine` | `<=>` | Cosine distance (most common for text/image embeddings) |
| `l2` | `<->` | Euclidean (L2) distance |
| `dot` | `<#>` | Negative inner product (useful when vectors are normalised) |

```php
VectorSearch::distanceOperator('cosine'); // '<=>'
VectorSearch::distanceOperator('l2');     // '<->'
VectorSearch::distanceOperator('dot');    // '<#>'
```

### `VectorSearch::formatVector(array $vector): string`

Converts a PHP float array to a PostgreSQL vector literal. Use this when building raw SQL:

```php
$literal = VectorSearch::formatVector([0.1, 0.2, 0.3]);
// Returns: '[0.1,0.2,0.3]'
```

---

## `VectorIndex`

`VectorIndex` generates `CREATE INDEX` DDL for vector columns. PyroSQL supports two index types:

### HNSW (Hierarchical Navigable Small World)

Best for high recall with low query latency. The trade-off is higher memory consumption and slower index build time compared to IVFFlat.

```php
use Weaver\ORM\PyroSQL\Vector\VectorIndex;

$ddl = VectorIndex::hnsw(
    column:          'embedding',
    distanceOp:      'cosine',
    m:               16,   // bidirectional links per node (higher = more recall, more memory)
    efConstruction:  64,   // build-time search list size (higher = better quality, slower build)
)->toSQL('articles', 'idx_articles_embedding_hnsw');

$connection->executeStatement($ddl);
```

Generates:
```sql
CREATE INDEX idx_articles_embedding_hnsw ON articles
USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)
```

### IVFFlat (Inverted File with Flat quantiser)

Better for very large datasets where memory is constrained. Build time is faster than HNSW, but recall is slightly lower for a given query budget.

```php
$ddl = VectorIndex::ivfflat(
    column:     'embedding',
    distanceOp: 'cosine',
    lists:      100,  // number of inverted lists (clusters); more = better recall, slower build
)->toSQL('articles', 'idx_articles_embedding_ivfflat');

$connection->executeStatement($ddl);
```

Generates:
```sql
CREATE INDEX idx_articles_embedding_ivfflat ON articles
USING ivfflat (embedding vector_cosine_ops) WITH (lists=100)
```

When `$indexName` is omitted the index name is auto-generated as `idx_{table}_{column}_{type}`:

```php
VectorIndex::hnsw('embedding')->toSQL('articles');
// → CREATE INDEX idx_articles_embedding_hnsw ON articles USING hnsw ...
```

---

## Full examples

### Semantic search over articles using OpenAI embeddings

```php
use Weaver\ORM\PyroSQL\Vector\VectorSearch;

class ArticleSearchService
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly OpenAIClient $openAi,
    ) {}

    /**
     * @return array<int, array{id: int, title: string, _distance: float}>
     */
    public function search(string $query, int $limit = 10): array
    {
        // 1. Embed the search query using the same model used to embed the articles
        $queryVector = $this->openAi->embeddings()->create([
            'model' => 'text-embedding-3-small',
            'input' => $query,
        ])->embeddings[0]->embedding;

        // 2. Build the nearest-neighbour SQL fragments
        $nn = VectorSearch::nearestNeighbors(
            column:     'embedding',
            vector:     $queryVector,
            k:          $limit,
            distanceOp: 'cosine',
        );

        // 3. Execute the query
        $sql = "SELECT id, title, {$nn['distanceColumn']}
                FROM articles
                WHERE published_at IS NOT NULL
                ORDER BY {$nn['orderBy']}
                LIMIT {$nn['limit']}";

        return $this->connection->fetchAllAssociative($sql);
    }
}

// Usage:
$results = $searchService->search('noise-cancelling headphones review');

foreach ($results as $row) {
    printf("[%.4f] %s\n", $row['_distance'], $row['title']);
}
```

### Product recommendation with 384-dimension vectors

This example uses a lightweight all-MiniLM-L6-v2 model (384 dimensions) to recommend products similar to one the user is currently viewing.

```php
use Weaver\ORM\PyroSQL\Vector\VectorSearch;
use Weaver\ORM\PyroSQL\Vector\VectorIndex;
use Weaver\ORM\PyroSQL\Vector\VectorColumnDefinition;

class ProductRecommendationService
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly EmbeddingClient $embedder,
    ) {}

    public function ensureIndex(): void
    {
        $ddl = VectorIndex::hnsw(
            column:         'embedding',
            distanceOp:     'cosine',
            m:              16,
            efConstruction: 64,
        )->toSQL('products', 'idx_products_embedding_hnsw');

        $this->connection->executeStatement($ddl);
    }

    public function findSimilar(int $productId, int $limit = 6): array
    {
        // Fetch the embedding of the source product
        $row = $this->connection->fetchAssociative(
            "SELECT embedding FROM products WHERE id = ?",
            [$productId],
        );

        if ($row === false || $row['embedding'] === null) {
            return [];
        }

        // The embedding is stored as a PyroSQL vector literal; parse it back to an array
        $storedVector = array_map('floatval', explode(',', trim((string) $row['embedding'], '[]')));

        // Find the k nearest neighbours, excluding the source product itself
        $nn = VectorSearch::nearestNeighbors(
            column:     'embedding',
            vector:     $storedVector,
            k:          $limit + 1,  // +1 because the source itself will appear
            distanceOp: 'cosine',
        );

        $sql = "SELECT id, name, price, {$nn['distanceColumn']}
                FROM products
                WHERE id != ? AND active = true
                ORDER BY {$nn['orderBy']}
                LIMIT {$nn['limit']}";

        return $this->connection->fetchAllAssociative($sql, [$productId]);
    }
}
```
