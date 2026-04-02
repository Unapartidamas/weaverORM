---
id: embeddable
title: Objetos Embebidos
---

Un **embeddable** (también llamado *objeto de valor* en terminología DDD) es una clase PHP cuyas propiedades se almacenan como columnas en la tabla de la entidad padre — sin join, sin tabla separada. Weaver gestiona el mapeo de columnas y la lógica de prefijos mediante un `AbstractEmbeddableMapper` dedicado.

## Qué problema resuelven los embeddables

Considera un `Customer` con direcciones de facturación y envío. Podrías añadir todas las columnas de dirección directamente al `CustomerMapper`, pero eso dispersa la lógica de dirección. O podrías normalizar las direcciones en una tabla separada, pero eso añade un join para cada consulta de cliente.

Los embeddables te dan una tercera opción: agrupar las columnas de dirección en un objeto de valor reutilizable (`Address`) que se aplana de forma transparente en la tabla padre.

```
customers
─────────────────────────────────────────
id
name
billing_street         ← de Address
billing_city           ← de Address
billing_country        ← de Address
billing_postal_code    ← de Address
shipping_street        ← de Address (reutilizado con diferente prefijo)
shipping_city
shipping_country
shipping_postal_code
```

## Definir una clase embeddable

La clase embeddable es un objeto PHP simple sin dependencias del ORM:

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

## Definir un EmbeddableMapper

Crea una clase que extienda `Weaver\ORM\Mapping\AbstractEmbeddableMapper`:

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

## Embeber en un mapper de entidad

Usa `EmbedMap::embed()` dentro del valor de retorno de `columns()` del mapper padre:

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
            // Embeber columnas de Address dos veces con diferentes prefijos
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

## SQL generado

Con prefijos `billing_` y `shipping_`:

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

## Prefijo de columna

El parámetro `prefix` pasado a `EmbedMap::embed()` se antepone a cada nombre de columna definido en el `EmbeddableMapper`. Los métodos `hydrate` y `extract` del mapper embeddable siempre usan los nombres de columna **sin prefijo**; Weaver maneja la traducción de prefijos automáticamente mediante `hydrateWithPrefix()` y `extractWithPrefix()`.

## Embeddable nullable

Cuando quieres representar la ausencia de una dirección como `null` en PHP, haz que todas las columnas embebidas sean nullable:

```php
// En las columns() de AddressMapper:
ColumnDefinition::string('street', 200)->nullable(),
ColumnDefinition::string('city', 100)->nullable(),
ColumnDefinition::string('country', 3)->nullable(),
ColumnDefinition::string('postal_code', 20)->nullable(),
```

En `hydrate` en el mapper padre:

```php
billingAddress: $row['billing_street'] !== null
    ? $this->addressMapper->hydrateWithPrefix($row, 'billing_')
    : null,
```

## Embeddables anidados

Los embeddables pueden a su vez embeber otros embeddables. Supón que `Address` contiene un `GeoCoordinate`:

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
// En AddressMapper — embeber GeoCoordinate con su propio prefijo
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

Cuando `AddressMapper` se embebe con prefijo `billing_`, las coordenadas anidadas se convierten en:

```sql
billing_street        VARCHAR(200) NOT NULL,
billing_city          VARCHAR(100) NOT NULL,
billing_country       VARCHAR(3)   NOT NULL,
billing_geo_latitude  DECIMAL(10, 7) NOT NULL,
billing_geo_longitude DECIMAL(10, 7) NOT NULL,
```

Los prefijos se concatenan: el prefijo padre (`billing_`) se antepone al prefijo hijo (`geo_`), dando `billing_geo_`.

## Cuándo usar embeddables

Usa embeddables cuando:

- Un concepto (dirección, dinero, rango de fechas) se repite en múltiples entidades.
- Los datos siempre se leen y escriben juntos con la entidad padre.
- No hay necesidad de consultar o referenciar el concepto de forma independiente.

Evita embeddables cuando:

- Necesitas consultar por el objeto embebido a través de múltiples entidades (`SELECT * FROM ... WHERE billing_city = 'Madrid'` está bien, pero hacer un join de vuelta a una segunda tabla sería más limpio).
- El concepto necesita su propio ciclo de vida (creado, actualizado, eliminado de forma independiente).
- El número de columnas haría que la tabla padre sea excesivamente ancha.
