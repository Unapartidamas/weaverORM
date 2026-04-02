---
id: embeddable
title: Embeddable Objects
---

An **embeddable** (also called a *value object* in DDD terminology) is a PHP class whose properties are stored as columns in the parent entity's table — no join, no separate table. Weaver manages the column mapping and prefix logic via a dedicated `AbstractEmbeddableMapper`.

## What problem embeddables solve

Consider a `Customer` with billing and shipping addresses. You could add all address columns directly to the `CustomerMapper`, but that scatters the address logic. Or you could normalise addresses into a separate table, but that adds a join for every customer query.

Embeddables give you a third option: group the address columns into a reusable value object (`Address`) that is transparently flattened into the parent table.

```
customers
─────────────────────────────────────────
id
name
billing_street         ← from Address
billing_city           ← from Address
billing_country        ← from Address
billing_postal_code    ← from Address
shipping_street        ← from Address (reused with different prefix)
shipping_city
shipping_country
shipping_postal_code
```

## Defining an embeddable class

The embeddable class is a plain PHP object with no ORM dependencies:

```php
<?php
// src/ValueObject/Address.php

declare(strict_types=1);

namespace App\ValueObject;

final class Address
{
    public function __construct(
        public readonly string  $street,
        public readonly string  $city,
        public readonly string  $country,
        public readonly ?string $postalCode = null,
    ) {}

    public function withCity(string $city): self
    {
        return new self(
            street:     $this->street,
            city:       $city,
            country:    $this->country,
            postalCode: $this->postalCode,
        );
    }
}
```

## Defining an EmbeddableMapper

Create a class extending `Weaver\ORM\Mapping\AbstractEmbeddableMapper`:

```php
<?php
// src/Mapper/AddressMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\ValueObject\Address;
use Weaver\ORM\Mapping\AbstractEmbeddableMapper;
use Weaver\ORM\Mapping\ColumnDefinition;

/** @extends AbstractEmbeddableMapper<Address> */
final class AddressMapper extends AbstractEmbeddableMapper
{
    public function columns(): array
    {
        return [
            ColumnDefinition::string('street', 200)->notNull(),
            ColumnDefinition::string('city', 100)->notNull(),
            ColumnDefinition::string('country', 3)->notNull(),
            ColumnDefinition::string('postal_code', 20)->nullable(),
        ];
    }

    public function embeddableClass(): string
    {
        return Address::class;
    }

    public function hydrate(array $row): Address
    {
        return new Address(
            street:     $row['street'],
            city:       $row['city'],
            country:    $row['country'],
            postalCode: $row['postal_code'] ?? null,
        );
    }

    public function extract(Address $embeddable): array
    {
        return [
            'street'      => $embeddable->street,
            'city'        => $embeddable->city,
            'country'     => $embeddable->country,
            'postal_code' => $embeddable->postalCode,
        ];
    }
}
```

## Embedding in an entity mapper

Use `EmbedMap::embed()` inside the parent mapper's `columns()` return value:

```php
<?php
// src/Mapper/CustomerMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\Customer;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\EmbedMap;
use Weaver\ORM\Mapping\SchemaDefinition;

final class CustomerMapper extends AbstractMapper
{
    public function __construct(
        private readonly AddressMapper $addressMapper,
    ) {}

    public function table(): string
    {
        return 'customers';
    }

    public function primaryKey(): string
    {
        return 'id';
    }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('name', 120)->notNull(),
            // Embed Address columns twice with different prefixes
            EmbedMap::embed($this->addressMapper, prefix: 'billing_'),
            EmbedMap::embed($this->addressMapper, prefix: 'shipping_'),
        );
    }

    public function hydrate(array $row): Customer
    {
        return new Customer(
            id:              (int) $row['id'],
            name:            $row['name'],
            billingAddress:  $this->addressMapper->hydrateWithPrefix($row, 'billing_'),
            shippingAddress: $this->addressMapper->hydrateWithPrefix($row, 'shipping_'),
        );
    }

    public function dehydrate(object $entity): array
    {
        /** @var Customer $entity */
        $data = [
            'id'   => $entity->id,
            'name' => $entity->name,
        ];

        foreach ($this->addressMapper->extractWithPrefix($entity->billingAddress, 'billing_') as $col => $val) {
            $data[$col] = $val;
        }

        foreach ($this->addressMapper->extractWithPrefix($entity->shippingAddress, 'shipping_') as $col => $val) {
            $data[$col] = $val;
        }

        return $data;
    }
}
```

## Generated SQL

With `billing_` and `shipping_` prefixes:

```sql
CREATE TABLE customers (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(120) NOT NULL,
    billing_street       VARCHAR(200) NOT NULL,
    billing_city         VARCHAR(100) NOT NULL,
    billing_country      VARCHAR(3)   NOT NULL,
    billing_postal_code  VARCHAR(20)  NULL,
    shipping_street      VARCHAR(200) NOT NULL,
    shipping_city        VARCHAR(100) NOT NULL,
    shipping_country     VARCHAR(3)   NOT NULL,
    shipping_postal_code VARCHAR(20)  NULL,
    PRIMARY KEY (id)
);
```

## Column prefix

The `prefix` parameter passed to `EmbedMap::embed()` is prepended to every column name defined in the `EmbeddableMapper`. The `hydrate` and `extract` methods of the embeddable mapper always use the **unprefixed** column names; Weaver handles prefix translation automatically via `hydrateWithPrefix()` and `extractWithPrefix()`.

## Nullable embeddable

When you want to represent the absence of an address as `null` in PHP, make all embedded columns nullable:

```php
// In AddressMapper columns():
ColumnDefinition::string('street', 200)->nullable(),
ColumnDefinition::string('city', 100)->nullable(),
ColumnDefinition::string('country', 3)->nullable(),
ColumnDefinition::string('postal_code', 20)->nullable(),
```

In `hydrate` on the parent mapper:

```php
billingAddress: $row['billing_street'] !== null
    ? $this->addressMapper->hydrateWithPrefix($row, 'billing_')
    : null,
```

## Nested embeddables

Embeddables can themselves embed other embeddables. Suppose `Address` contains a `GeoCoordinate`:

```php
// src/ValueObject/GeoCoordinate.php
final class GeoCoordinate
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}
}
```

```php
// src/Mapper/GeoCoordinateMapper.php
final class GeoCoordinateMapper extends AbstractEmbeddableMapper
{
    public function columns(): array
    {
        return [
            ColumnDefinition::decimal('latitude', 10, 7)->notNull(),
            ColumnDefinition::decimal('longitude', 10, 7)->notNull(),
        ];
    }
    // ... embeddableClass, hydrate, extract
}
```

```php
// In AddressMapper — embed GeoCoordinate with its own prefix
public function __construct(private readonly GeoCoordinateMapper $geoMapper) {}

public function columns(): array
{
    return [
        ColumnDefinition::string('street', 200)->notNull(),
        ColumnDefinition::string('city', 100)->notNull(),
        ColumnDefinition::string('country', 3)->notNull(),
        EmbedMap::embed($this->geoMapper, prefix: 'geo_'),
    ];
}
```

When `AddressMapper` is embedded with prefix `billing_`, the nested coordinates become:

```sql
billing_street        VARCHAR(200) NOT NULL,
billing_city          VARCHAR(100) NOT NULL,
billing_country       VARCHAR(3)   NOT NULL,
billing_geo_latitude  DECIMAL(10, 7) NOT NULL,
billing_geo_longitude DECIMAL(10, 7) NOT NULL,
```

Prefixes are concatenated: the parent prefix (`billing_`) is prepended to the child prefix (`geo_`), giving `billing_geo_`.

## When to use embeddables

Use embeddables when:

- A concept (address, money, date range) recurs across multiple entities.
- The data is always read and written together with the parent entity.
- There is no need to query or reference the concept independently.

Avoid embeddables when:

- You need to query by the embedded object across multiple entities (`SELECT * FROM ... WHERE billing_city = 'London'` is fine, but joining back to a second table would be cleaner).
- The concept needs its own lifecycle (created, updated, deleted independently).
- The number of columns would make the parent table excessively wide.
