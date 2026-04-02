---
id: inheritance
title: Herencia de Mapeo
---

Weaver ORM supports three inheritance patterns. Choose based on how similar your subclass schemas are and how often you need to query across all types in a single `SELECT`.

## Strategies at a glance

| Strategy | Tables | Best when |
|---|---|---|
| Mapped Superclass | One per concrete subclass | Shared columns, no polymorphic queries |
| Single Table Inheritance (STI) | One shared table | Mostly similar columns, ≤6 subtypes |
| Class Table Inheritance (CTI) | One per class | Very different columns, frequent joins |

---

## Mapped Superclass

A *mapped superclass* is an abstract PHP class that contributes columns to its subclasses but has no table of its own. Each concrete subclass gets its own table containing the superclass columns plus its own.

Use this when you have common fields (`createdAt`, `updatedAt`, `createdBy`) shared across many unrelated entities, and you do not need polymorphic queries across them.

### Pattern

```php
// Abstract PHP class — no Weaver coupling
abstract class TimestampedEntity
{
    public function __construct(
        protected \DateTimeImmutable $createdAt,
        protected ?\DateTimeImmutable $updatedAt,
    ) {}

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
```

Abstract base mapper — defines the shared columns as a reusable method:

```php
abstract class TimestampedEntityMapper extends AbstractMapper
{
    protected function timestampColumns(): array
    {
        return [
            ColumnDefinition::datetimeImmutable('created_at')->notNull(),
            ColumnDefinition::datetimeImmutable('updated_at')->nullable(),
        ];
    }
}
```

Concrete subclass mapper — spreads the shared columns:

```php
final class ArticleMapper extends TimestampedEntityMapper
{
    public function table(): string { return 'articles'; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('title')->notNull(),
            ColumnDefinition::text('body')->notNull(),
            ...$this->timestampColumns(),   // spread shared columns
        );
    }

    public function hydrate(array $row): Article
    {
        return new Article(
            id:        (int) $row['id'],
            title:     $row['title'],
            body:      $row['body'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: isset($row['updated_at'])
                ? new \DateTimeImmutable($row['updated_at'])
                : null,
        );
    }
}
```

Generated SQL for `articles`:

```sql
CREATE TABLE articles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255) NOT NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NULL,
    PRIMARY KEY (id)
);
```

A separate `products` mapper using the same base would generate a `products` table with its own `created_at` / `updated_at` columns — no relation to `articles` in the schema.

---

## Single Table Inheritance (STI)

All subclasses share **one table**. A *discriminator column* tells Weaver which PHP class to hydrate for a given row.

Use STI when:
- Subclasses have mostly the same columns.
- You need to query across all types in a single `SELECT`.
- You have ≤6–8 subtypes (more leads to too many nullable columns).

### Example: Payment types

```php
// Entity hierarchy
abstract class Payment
{
    public function __construct(
        protected int $id,
        protected string $reference,
        protected string $amount,   // decimal as string
        protected \DateTimeImmutable $paidAt,
    ) {}
}

final class CreditCardPayment extends Payment
{
    public function __construct(
        int $id, string $reference, string $amount, \DateTimeImmutable $paidAt,
        private string $cardLast4,
        private string $cardBrand,
    ) {
        parent::__construct($id, $reference, $amount, $paidAt);
    }
}

final class BankTransferPayment extends Payment
{
    public function __construct(
        int $id, string $reference, string $amount, \DateTimeImmutable $paidAt,
        private string $ibanLast4,
        private string $bankName,
    ) {
        parent::__construct($id, $reference, $amount, $paidAt);
    }
}
```

### Mapper

```php
final class PaymentMapper extends AbstractMapper
{
    public function table(): string { return 'payments'; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('type', 30)->notNull(),          // discriminator
            ColumnDefinition::string('reference', 50)->notNull(),
            ColumnDefinition::decimal('amount', 12, 2)->notNull(),
            ColumnDefinition::datetimeImmutable('paid_at')->notNull(),
            // CreditCard columns — nullable for other types
            ColumnDefinition::string('card_last4', 4)->nullable(),
            ColumnDefinition::string('card_brand', 20)->nullable(),
            // BankTransfer columns — nullable for other types
            ColumnDefinition::string('iban_last4', 4)->nullable(),
            ColumnDefinition::string('bank_name', 80)->nullable(),
        );
    }

    public function discriminatorColumn(): string { return 'type'; }

    public function discriminatorMap(): array
    {
        return [
            'credit_card'   => CreditCardPayment::class,
            'bank_transfer' => BankTransferPayment::class,
        ];
    }

    public function hydrate(array $row): Payment
    {
        return match ($row['type']) {
            'credit_card'   => new CreditCardPayment(
                id:        (int) $row['id'],
                reference: $row['reference'],
                amount:    $row['amount'],
                paidAt:    new \DateTimeImmutable($row['paid_at']),
                cardLast4: $row['card_last4'],
                cardBrand: $row['card_brand'],
            ),
            'bank_transfer' => new BankTransferPayment(
                id:        (int) $row['id'],
                reference: $row['reference'],
                amount:    $row['amount'],
                paidAt:    new \DateTimeImmutable($row['paid_at']),
                ibanLast4: $row['iban_last4'],
                bankName:  $row['bank_name'],
            ),
            default => throw new \UnexpectedValueException("Unknown payment type: {$row['type']}"),
        };
    }

    public function dehydrate(object $entity): array
    {
        $base = [
            'id'        => $entity->id,
            'reference' => $entity->reference,
            'amount'    => $entity->amount,
            'paid_at'   => $entity->paidAt->format('Y-m-d H:i:s'),
        ];

        if ($entity instanceof CreditCardPayment) {
            return $base + [
                'type'       => 'credit_card',
                'card_last4' => $entity->getCardLast4(),
                'card_brand' => $entity->getCardBrand(),
                'iban_last4' => null,
                'bank_name'  => null,
            ];
        }

        if ($entity instanceof BankTransferPayment) {
            return $base + [
                'type'       => 'bank_transfer',
                'card_last4' => null,
                'card_brand' => null,
                'iban_last4' => $entity->getIbanLast4(),
                'bank_name'  => $entity->getBankName(),
            ];
        }

        throw new \UnexpectedValueException('Unknown payment type');
    }
}
```

### Generated SQL

```sql
CREATE TABLE payments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type        VARCHAR(30) NOT NULL,
    reference   VARCHAR(50) NOT NULL,
    amount      DECIMAL(12, 2) NOT NULL,
    paid_at     DATETIME NOT NULL,
    card_last4  VARCHAR(4) NULL,
    card_brand  VARCHAR(20) NULL,
    iban_last4  VARCHAR(4) NULL,
    bank_name   VARCHAR(80) NULL,
    PRIMARY KEY (id),
    INDEX idx_payments_type (type)
);
```

### Querying across all types

```php
// Returns a mix of CreditCardPayment and BankTransferPayment objects
$payments = $paymentRepository->findAll();

// Filter by type using the discriminator value
$creditCardPayments = $paymentRepository->query()
    ->where('type', 'credit_card')
    ->get();
```

:::tip
If your `nullable_columns / total_columns` ratio exceeds ~40%, STI wastes significant storage. Migrate to CTI instead.
:::

---

## Class Table Inheritance (CTI)

Each class in the hierarchy has its own table. The parent table holds common columns; each subclass table holds only its own columns, linked to the parent by a shared primary key.

Use CTI when:
- Subclasses have substantially different columns.
- You join parent and child data frequently, but rarely need polymorphic queries.

### Example: Content types

```php
// Entity hierarchy
abstract class Content
{
    public function __construct(
        protected int $id,
        protected string $title,
        protected \DateTimeImmutable $createdAt,
    ) {}
}

final class BlogPost extends Content
{
    public function __construct(
        int $id, string $title, \DateTimeImmutable $createdAt,
        private string $body,
        private string $authorName,
    ) {
        parent::__construct($id, $title, $createdAt);
    }
}

final class Product extends Content
{
    public function __construct(
        int $id, string $title, \DateTimeImmutable $createdAt,
        private string $sku,
        private string $price,
    ) {
        parent::__construct($id, $title, $createdAt);
    }
}
```

### Parent mapper

```php
final class ContentMapper extends AbstractMapper
{
    public function table(): string { return 'content'; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('title')->notNull(),
            ColumnDefinition::datetimeImmutable('created_at')->notNull(),
        );
    }

    public function entityClass(): string { return Content::class; }

    public function hydrate(array $row): Content
    {
        // In CTI the parent mapper is never used directly for hydration
        throw new \LogicException('Use a subclass mapper to hydrate Content');
    }

    public function dehydrate(object $entity): array
    {
        return [
            'id'         => $entity->id,
            'title'      => $entity->title,
            'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
```

### Subclass mapper

```php
final class BlogPostMapper extends AbstractMapper
{
    public function __construct(
        private readonly ContentMapper $parentMapper,
    ) {}

    public function table(): string { return 'blog_posts'; }

    // Declare the parent mapper class — Weaver will JOIN the tables automatically
    public function parentMapper(): string { return ContentMapper::class; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            // id is shared with content.id — no AUTO_INCREMENT here
            ColumnDefinition::integer('id')->primaryKey()->unsigned(),
            ColumnDefinition::text('body')->notNull(),
            ColumnDefinition::string('author_name', 120)->notNull(),
        );
    }

    public function entityClass(): string { return BlogPost::class; }

    public function hydrate(array $row): BlogPost
    {
        return new BlogPost(
            id:         (int) $row['id'],
            title:      $row['title'],        // from parent table
            createdAt:  new \DateTimeImmutable($row['created_at']),
            body:       $row['body'],
            authorName: $row['author_name'],
        );
    }

    public function dehydrate(object $entity): array
    {
        // Only the subclass-owned columns; parent columns go through ContentMapper
        return [
            'id'          => $entity->getId(),
            'body'        => $entity->getBody(),
            'author_name' => $entity->getAuthorName(),
        ];
    }
}
```

### Generated SQL

```sql
CREATE TABLE content (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE blog_posts (
    id           INT UNSIGNED NOT NULL,
    body         TEXT NOT NULL,
    author_name  VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_blog_posts_content FOREIGN KEY (id) REFERENCES content (id)
);

CREATE TABLE products (
    id     INT UNSIGNED NOT NULL,
    sku    VARCHAR(100) NOT NULL,
    price  DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_products_content FOREIGN KEY (id) REFERENCES content (id)
);
```

When Weaver fetches a `BlogPost`, it issues a `JOIN` between `content` and `blog_posts` automatically.

### Choosing between STI and CTI

| Criterion | Prefer STI | Prefer CTI |
|---|---|---|
| Column overlap | \>80% | \<60% |
| Polymorphic queries | Frequent | Rare |
| Number of subtypes | ≤8 | Any |
| Schema clarity | Less important | Important |
| Write performance | Single INSERT | Two INSERTs per row |
