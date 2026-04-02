---
id: embeddable
title: Objets intégrables
---

Un **objet intégrable** (aussi appelé *objet valeur* dans la terminologie DDD) est une classe PHP dont les propriétés sont stockées sous forme de colonnes dans la table de l'entité parente — pas de jointure, pas de table séparée. Weaver gère le mapping des colonnes et la logique de préfixe via un `AbstractEmbeddableMapper` dédié.

## Quel problème les objets intégrables résolvent

Considérez un `Customer` avec des adresses de facturation et de livraison. Vous pourriez ajouter toutes les colonnes d'adresse directement au `CustomerMapper`, mais cela disperse la logique d'adresse. Ou vous pourriez normaliser les adresses dans une table séparée, mais cela ajoute une jointure pour chaque requête de client.

Les objets intégrables vous offrent une troisième option : regrouper les colonnes d'adresse dans un objet valeur réutilisable (`Address`) qui est transparentement aplati dans la table parente.

```
customers
─────────────────────────────────────────
id
name
billing_street         ← de Address
billing_city           ← de Address
billing_country        ← de Address
billing_postal_code    ← de Address
shipping_street        ← de Address (réutilisé avec un préfixe différent)
shipping_city
shipping_country
shipping_postal_code
```

## Définir une classe intégrable

La classe intégrable est un objet PHP simple sans dépendances ORM :

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

## Définir un EmbeddableMapper

Créez une classe étendant `Weaver\ORM\Mapping\AbstractEmbeddableMapper` :

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

## Intégration dans un mapper d'entité

Utilisez `EmbedMap::embed()` dans la valeur de retour de `columns()` du mapper parent :

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
            // Intégrer les colonnes Address deux fois avec des préfixes différents
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

## SQL généré

Avec les préfixes `billing_` et `shipping_` :

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

## Préfixe de colonne

Le paramètre `prefix` passé à `EmbedMap::embed()` est ajouté avant chaque nom de colonne défini dans l'`EmbeddableMapper`. Les méthodes `hydrate` et `extract` du mapper intégrable utilisent toujours les noms de colonnes **sans préfixe** ; Weaver gère automatiquement la traduction du préfixe via `hydrateWithPrefix()` et `extractWithPrefix()`.

## Objet intégrable nullable

Quand vous souhaitez représenter l'absence d'une adresse par `null` en PHP, rendez toutes les colonnes intégrées nullable :

```php
// Dans AddressMapper columns():
ColumnDefinition::string('street', 200)->nullable(),
ColumnDefinition::string('city', 100)->nullable(),
ColumnDefinition::string('country', 3)->nullable(),
ColumnDefinition::string('postal_code', 20)->nullable(),
```

Dans `hydrate` sur le mapper parent :

```php
billingAddress: $row['billing_street'] !== null
    ? $this->addressMapper->hydrateWithPrefix($row, 'billing_')
    : null,
```

## Objets intégrables imbriqués

Les objets intégrables peuvent eux-mêmes intégrer d'autres objets intégrables. Supposons qu'`Address` contienne une `GeoCoordinate` :

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
// Dans AddressMapper — intégrer GeoCoordinate avec son propre préfixe
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

Quand `AddressMapper` est intégré avec le préfixe `billing_`, les coordonnées imbriquées deviennent :

```sql
billing_street        VARCHAR(200) NOT NULL,
billing_city          VARCHAR(100) NOT NULL,
billing_country       VARCHAR(3)   NOT NULL,
billing_geo_latitude  DECIMAL(10, 7) NOT NULL,
billing_geo_longitude DECIMAL(10, 7) NOT NULL,
```

Les préfixes sont concaténés : le préfixe parent (`billing_`) est ajouté avant le préfixe enfant (`geo_`), donnant `billing_geo_`.

## Quand utiliser les objets intégrables

Utilisez les objets intégrables quand :

- Un concept (adresse, argent, plage de dates) est récurrent dans plusieurs entités.
- Les données sont toujours lues et écrites ensemble avec l'entité parente.
- Il n'est pas nécessaire d'interroger ou de référencer le concept indépendamment.

Évitez les objets intégrables quand :

- Vous devez effectuer des requêtes sur l'objet intégré dans plusieurs entités (`SELECT * FROM ... WHERE billing_city = 'Paris'` est acceptable, mais joindre en retour vers une seconde table serait plus propre).
- Le concept nécessite son propre cycle de vie (créé, mis à jour, supprimé indépendamment).
- Le nombre de colonnes rendrait la table parente excessivement large.
