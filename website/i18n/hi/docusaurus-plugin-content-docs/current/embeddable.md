---
id: embeddable
title: Embeddable ऑब्जेक्ट्स
---

एक **embeddable** (DDD terminology में *value object* भी कहलाता है) एक PHP क्लास है जिसकी properties parent एंटिटी की टेबल में कॉलम के रूप में स्टोर की जाती हैं — कोई join नहीं, कोई अलग टेबल नहीं। Weaver एक समर्पित `AbstractEmbeddableMapper` के माध्यम से कॉलम मैपिंग और prefix लॉजिक प्रबंधित करता है।

## Embeddables क्या समस्या हल करते हैं

billing और shipping addresses के साथ एक `Customer` पर विचार करें। आप सभी address कॉलम सीधे `CustomerMapper` में जोड़ सकते हैं, लेकिन यह address लॉजिक को बिखेर देता है। या आप addresses को एक अलग टेबल में normalize कर सकते हैं, लेकिन यह हर customer क्वेरी के लिए एक join जोड़ता है।

Embeddables आपको एक तीसरा विकल्प देते हैं: address कॉलम को एक पुन: उपयोग योग्य value object (`Address`) में group करें जो parent टेबल में transparently flatten हो जाता है।

```
customers
─────────────────────────────────────────
id
name
billing_street         ← Address से
billing_city           ← Address से
billing_country        ← Address से
billing_postal_code    ← Address से
shipping_street        ← Address से (अलग prefix के साथ पुन: उपयोग)
shipping_city
shipping_country
shipping_postal_code
```

## एक embeddable क्लास परिभाषित करना

Embeddable क्लास ORM निर्भरताओं के बिना एक सामान्य PHP ऑब्जेक्ट है:

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

## एक EmbeddableMapper परिभाषित करना

`Weaver\ORM\Mapping\AbstractEmbeddableMapper` को एक्सटेंड करने वाली एक क्लास बनाएं:

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

## एंटिटी मैपर में Embedding

Parent मैपर के `columns()` रिटर्न वैल्यू के अंदर `EmbedMap::embed()` का उपयोग करें:

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
            // Address कॉलम को अलग-अलग prefixes के साथ दो बार embed करें
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

## जनरेट किया गया SQL

`billing_` और `shipping_` prefixes के साथ:

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

## कॉलम prefix

`EmbedMap::embed()` को pass किया गया `prefix` parameter `EmbeddableMapper` में परिभाषित हर कॉलम नाम के आगे जोड़ा जाता है। Embeddable मैपर के `hydrate` और `extract` मेथड हमेशा **unprefixed** कॉलम नाम का उपयोग array key के रूप में करते हैं; Weaver `hydrateWithPrefix()` और `extractWithPrefix()` के माध्यम से prefix translation स्वचालित रूप से संभालता है।

## Nullable embeddable

जब आप PHP में एक address की अनुपस्थिति को `null` के रूप में represent करना चाहते हैं, तो सभी embedded कॉलम nullable बनाएं:

```php
// AddressMapper columns() में:
ColumnDefinition::string('street', 200)->nullable(),
ColumnDefinition::string('city', 100)->nullable(),
ColumnDefinition::string('country', 3)->nullable(),
ColumnDefinition::string('postal_code', 20)->nullable(),
```

Parent मैपर में `hydrate` में:

```php
billingAddress: $row['billing_street'] !== null
    ? $this->addressMapper->hydrateWithPrefix($row, 'billing_')
    : null,
```

## Nested embeddables

Embeddables खुद अन्य embeddables embed कर सकते हैं। मान लीजिए `Address` में एक `GeoCoordinate` है:

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
// AddressMapper में — GeoCoordinate को अपने prefix के साथ embed करें
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

जब `AddressMapper` prefix `billing_` के साथ embedded हो, तो nested coordinates बन जाते हैं:

```sql
billing_street        VARCHAR(200) NOT NULL,
billing_city          VARCHAR(100) NOT NULL,
billing_country       VARCHAR(3)   NOT NULL,
billing_geo_latitude  DECIMAL(10, 7) NOT NULL,
billing_geo_longitude DECIMAL(10, 7) NOT NULL,
```

Prefixes concatenate होते हैं: parent prefix (`billing_`) child prefix (`geo_`) से पहले जुड़ता है, जो `billing_geo_` देता है।

## Embeddables का उपयोग कब करें

Embeddables का उपयोग करें जब:

- एक concept (address, money, date range) कई entities में दोहराता है।
- Data हमेशा parent entity के साथ पढ़ा और लिखा जाता है।
- Concept को स्वतंत्र रूप से query या reference करने की कोई आवश्यकता नहीं है।

Embeddables से बचें जब:

- आपको कई entities में embedded ऑब्जेक्ट द्वारा query करने की आवश्यकता हो (`SELECT * FROM ... WHERE billing_city = 'London'` ठीक है, लेकिन दूसरी टेबल पर वापस joining अधिक स्वच्छ होगी)।
- Concept का अपना lifecycle चाहिए (स्वतंत्र रूप से created, updated, deleted)।
- कॉलम की संख्या parent टेबल को अत्यधिक चौड़ा बना देगी।
