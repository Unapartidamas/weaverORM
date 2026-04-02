---
id: inheritance
title: Mapping d'héritage
---

Weaver ORM supporte trois schémas d'héritage. Choisissez en fonction de la similarité des schémas de vos sous-classes et de la fréquence à laquelle vous avez besoin d'interroger tous les types dans un seul `SELECT`.

## Stratégies en un coup d'œil

| Stratégie | Tables | Idéal quand |
|---|---|---|
| Superclasse mappée | Une par sous-classe concrète | Colonnes partagées, pas de requêtes polymorphiques |
| Héritage de table unique (STI) | Une table partagée | Colonnes majoritairement similaires, ≤6 sous-types |
| Héritage de table de classe (CTI) | Une par classe | Colonnes très différentes, jointures fréquentes |

---

## Superclasse mappée

Une *superclasse mappée* est une classe PHP abstraite qui contribue des colonnes à ses sous-classes mais n'a pas de table propre. Chaque sous-classe concrète obtient sa propre table contenant les colonnes de la superclasse plus les siennes.

Utilisez ceci quand vous avez des champs communs (`createdAt`, `updatedAt`, `createdBy`) partagés entre de nombreuses entités non liées, et que vous n'avez pas besoin de requêtes polymorphiques.

### Schéma

```php
// Classe PHP abstraite — pas de couplage Weaver
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

Mapper de base abstrait — définit les colonnes partagées comme méthode réutilisable :

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

Mapper de sous-classe concrète — diffuse les colonnes partagées :

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
            ...$this->timestampColumns(),   // diffusion des colonnes partagées
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

SQL généré pour `articles` :

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

Un mapper `products` séparé utilisant la même base génèrerait une table `products` avec ses propres colonnes `created_at` / `updated_at` — sans relation avec `articles` dans le schéma.

---

## Héritage de table unique (STI)

Toutes les sous-classes partagent **une seule table**. Une *colonne discriminateur* indique à Weaver quelle classe PHP hydrater pour une ligne donnée.

Utilisez STI quand :
- Les sous-classes ont majoritairement les mêmes colonnes.
- Vous avez besoin d'interroger tous les types dans un seul `SELECT`.
- Vous avez ≤6–8 sous-types (plus conduit à trop de colonnes nullable).

### Exemple : Types de paiement

```php
// Hiérarchie d'entités
abstract class Payment
{
    public function __construct(
        protected int $id,
        protected string $reference,
        protected string $amount,   // décimal sous forme de chaîne
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
            ColumnDefinition::string('type', 30)->notNull(),          // discriminateur
            ColumnDefinition::string('reference', 50)->notNull(),
            ColumnDefinition::decimal('amount', 12, 2)->notNull(),
            ColumnDefinition::datetimeImmutable('paid_at')->notNull(),
            // Colonnes CreditCard — nullable pour les autres types
            ColumnDefinition::string('card_last4', 4)->nullable(),
            ColumnDefinition::string('card_brand', 20)->nullable(),
            // Colonnes BankTransfer — nullable pour les autres types
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
            default => throw new \UnexpectedValueException("Type de paiement inconnu : {$row['type']}"),
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

        throw new \UnexpectedValueException('Type de paiement inconnu');
    }
}
```

### SQL généré

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

### Requêtes sur tous les types

```php
// Retourne un mélange d'objets CreditCardPayment et BankTransferPayment
$payments = $paymentRepository->findAll();

// Filtrer par type en utilisant la valeur discriminateur
$creditCardPayments = $paymentRepository->query()
    ->where('type', 'credit_card')
    ->get();
```

:::tip
Si votre ratio `colonnes_nullables / colonnes_totales` dépasse ~40 %, STI gaspille un espace de stockage significatif. Migrez vers CTI à la place.
:::

---

## Héritage de table de classe (CTI)

Chaque classe dans la hiérarchie a sa propre table. La table parente détient les colonnes communes ; chaque table de sous-classe ne détient que ses propres colonnes, liées au parent par une clé primaire partagée.

Utilisez CTI quand :
- Les sous-classes ont des colonnes substantiellement différentes.
- Vous joignez fréquemment les données parent et enfant, mais avez rarement besoin de requêtes polymorphiques.

### Exemple : Types de contenu

```php
// Hiérarchie d'entités
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

### Mapper parent

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
        // En CTI, le mapper parent n'est jamais utilisé directement pour l'hydratation
        throw new \LogicException('Utilisez un mapper de sous-classe pour hydrater Content');
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

### Mapper de sous-classe

```php
final class BlogPostMapper extends AbstractMapper
{
    public function __construct(
        private readonly ContentMapper $parentMapper,
    ) {}

    public function table(): string { return 'blog_posts'; }

    // Déclarer la classe du mapper parent — Weaver fera automatiquement la JOIN des tables
    public function parentMapper(): string { return ContentMapper::class; }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            // id est partagé avec content.id — pas d'AUTO_INCREMENT ici
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
            title:      $row['title'],        // de la table parente
            createdAt:  new \DateTimeImmutable($row['created_at']),
            body:       $row['body'],
            authorName: $row['author_name'],
        );
    }

    public function dehydrate(object $entity): array
    {
        // Uniquement les colonnes appartenant à la sous-classe ; les colonnes parentes passent par ContentMapper
        return [
            'id'          => $entity->getId(),
            'body'        => $entity->getBody(),
            'author_name' => $entity->getAuthorName(),
        ];
    }
}
```

### SQL généré

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

Quand Weaver récupère un `BlogPost`, il émet automatiquement un `JOIN` entre `content` et `blog_posts`.

### Choisir entre STI et CTI

| Critère | Préférer STI | Préférer CTI |
|---|---|---|
| Chevauchement de colonnes | `>80%` | `<60%` |
| Requêtes polymorphiques | Fréquentes | Rares |
| Nombre de sous-types | ≤8 | Quelconque |
| Clarté du schéma | Moins importante | Importante |
| Performance d'écriture | INSERT unique | Deux INSERTs par ligne |
