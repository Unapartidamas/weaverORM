---
id: entity-mapping
title: एंटिटी मैपिंग
---

Weaver ORM डोमेन ऑब्जेक्ट्स को persistence मेटाडेटा से अलग करता है, सभी मैपिंग जानकारी को एक समर्पित **मैपर क्लास** में रखकर। यह पृष्ठ मैपर कॉन्फ़िगरेशन के हर पहलू को कवर करता है।

## एट्रिब्यूट्स के बजाय मैपर्स क्यों?

Doctrine ORM PHP 8 एट्रिब्यूट्स के माध्यम से मैपिंग मेटाडेटा को सीधे एंटिटी क्लास पर रखता है:

```php
// Doctrine दृष्टिकोण — एंटिटी को डेटाबेस के बारे में पता है
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
}
```

Weaver उन्हें कड़ाई से अलग रखता है:

```
एंटिटी क्लास        →  सामान्य PHP ऑब्जेक्ट, ORM निर्भरताएं शून्य
मैपर क्लास          →  सभी persistence ज्ञान यहाँ रहता है
```

लाभ:
- **शून्य रनटाइम रिफ्लेक्शन।** मैपर सामान्य PHP है जो एरे और स्केलर लौटाता है।
- **कोई प्रॉक्सी क्लास नहीं।** कोई ऑन-डिस्क कोड जनरेशन आवश्यक नहीं।
- **वर्कर-सुरक्षित।** मैपर्स प्रति-अनुरोध कोई स्थिति नहीं रखते।
- **अलगाव में टेस्टयोग्य।** Symfony बूट किए बिना यूनिट टेस्ट में मैपर को इंस्टेंशिएट और इंस्पेक्ट करें।
- **पूरी तरह से grep-योग्य।** हर कॉलम नाम, हर टाइप, हर विकल्प सामान्य टेक्स्ट में दिखाई देता है और `git diff` में दिखता है।

## मैपर बनाम एंटिटी: जिम्मेदारियाँ

| चिंता | में रहता है |
|---|---|
| बिज़नेस लॉजिक, invariants | एंटिटी क्लास |
| प्रॉपर्टीज़ और PHP टाइप्स | एंटिटी क्लास |
| टेबल नाम और स्कीमा | मैपर |
| कॉलम नाम, टाइप्स, विकल्प | मैपर |
| इंडेक्स और constraints | मैपर |
| हाइड्रेशन (row → entity) | मैपर |
| निष्कर्षण (entity → row) | मैपर |
| रिलेशन्स | मैपर |

## बुनियादी एंटिटी परिभाषा

एंटिटी कोई भी PHP क्लास है। यह कुछ भी एक्सटेंड नहीं करती, कुछ भी इम्प्लीमेंट नहीं करती, या `Weaver\ORM` से कुछ भी इम्पोर्ट नहीं करती।

```php
<?php
// src/Entity/User.php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isActive,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public function withEmail(string $email): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            email:     $email,
            isActive:  $this->isActive,
            createdAt: $this->createdAt,
        );
    }
}
```

एंटिटी हो सकती है:
- **Immutable** (अनुशंसित) — म्यूटेटिंग मेथड नए इंस्टेंस लौटाते हैं
- **Mutable** — public प्रॉपर्टीज़ या setters ठीक हैं
- **Abstract** — इनहेरिटेंस hierarchies के लिए

## AbstractMapper

हर एंटिटी को एक मैपर की आवश्यकता होती है। `Weaver\ORM\Mapping\AbstractMapper` को एक्सटेंड करने वाली एक क्लास बनाएं और आवश्यक मेथड लागू करें।

```php
<?php
// src/Mapper/UserMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\User;
use DateTimeImmutable;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\SchemaDefinition;

final class UserMapper extends AbstractMapper
{
    public function table(): string
    {
        return 'users';
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('name', 120)->notNull(),
            ColumnDefinition::string('email', 254)->unique()->notNull(),
            ColumnDefinition::boolean('is_active')->notNull()->default(true),
            ColumnDefinition::datetime('created_at')->notNull(),
        );
    }

    public function hydrate(array $row): User
    {
        return new User(
            id:        (int) $row['id'],
            name:      $row['name'],
            email:     $row['email'],
            isActive:  (bool) $row['is_active'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    public function dehydrate(object $entity): array
    {
        /** @var User $entity */
        $data = [
            'name'       => $entity->name,
            'email'      => $entity->email,
            'is_active'  => $entity->isActive,
            'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
        ];

        if ($entity->id !== null) {
            $data['id'] = $entity->id;
        }

        return $data;
    }
}
```

### आवश्यक मैपर मेथड

| मेथड | उद्देश्य |
|---|---|
| `table(): string` | डेटाबेस में टेबल नाम |
| `primaryKey(): string\|array` | प्राइमरी की के लिए कॉलम नाम |
| `schema(): SchemaDefinition` | DDL और माइग्रेशन के लिए सभी कॉलम परिभाषाएं |
| `hydrate(array $row): object` | raw डेटाबेस row से एंटिटी बनाएं |
| `dehydrate(object $entity): array` | एंटिटी को column => value एरे में serialize करें |

### वैकल्पिक मैपर मेथड

| मेथड | उद्देश्य |
|---|---|
| `readOnly(): bool` | view-backed एंटिटीज़ के लिए `true` लौटाएं (कोई INSERT/UPDATE/DELETE नहीं) |
| `discriminatorColumn(): ?string` | Single Table Inheritance के लिए उपयोग |
| `discriminatorMap(): array` | Single Table Inheritance के लिए उपयोग |
| `parentMapper(): ?string` | Class Table Inheritance के लिए उपयोग |

## कॉलम टाइप्स

सभी कॉलम परिभाषाएं `ColumnDefinition` पर static factory मेथड का उपयोग करती हैं। प्रत्येक मेथड एक fluent configuration API के साथ `ColumnDefinition` इंस्टेंस लौटाता है।

### string

`VARCHAR(n)` पर मैप होता है। डिफ़ॉल्ट लंबाई 255 है।

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer, bigint, smallint

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float और decimal

वित्तीय मूल्यों के लिए `decimal` का उपयोग करें; निर्देशांक और माप के लिए `float`।

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

सटीकता बनाए रखने के लिए `decimal` को string के रूप में hydrate करें:

```php
price: $row['price'],  // string के रूप में रखें, Money value object को पास करें
```

### boolean

MySQL पर `TINYINT(1)`, PostgreSQL/SQLite पर `BOOLEAN` पर मैप होता है।

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

`hydrate` में हमेशा स्पष्ट रूप से cast करें:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` एक mutable `\DateTime` लौटाता है। नए कोड के लिए `datetimeImmutable` को प्राथमिकता दें:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

हाइड्रेशन:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

निष्कर्षण:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

`JSON` पर मैप होता है (MySQL 5.7.8+, PostgreSQL, SQLite)। आप `hydrate` / `dehydrate` में encoding/decoding नियंत्रित करते हैं।

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

हाइड्रेशन:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

निष्कर्षण:

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text, blob

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid (UUID as CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## प्राइमरी की टाइप्स

### Auto-increment integer

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

Weaver `INSERT` से `id` को छोड़ देता है जब मूल्य `null` होता है और जनरेट मूल्य को स्वचालित रूप से पढ़ता है।

### UUID v4 (यादृच्छिक)

```php
ColumnDefinition::guid('id')->primaryKey()
```

persist करने से पहले एंटिटी factory मेथड में UUID जनरेट करें:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (time-ordered, अनुशंसित)

UUID v7 में एक मिलीसेकंड टाइमस्टैम्प प्रीफिक्स शामिल है, जो कीज़ को monotonically increasing बनाता है और random UUIDs की तुलना में B-tree पेज स्प्लिट को नाटकीय रूप से कम करता है।

```php
ColumnDefinition::guid('id')->primaryKey()
```

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v7(), name: $name);
}
```

### Natural string key

जब business key स्वाभाविक रूप से unique हो (country code, currency code, slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Composite primary key

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## कॉलम विकल्प

सभी विकल्प `ColumnDefinition` पर fluent मेथड के रूप में उपलब्ध हैं:

| मेथड | प्रभाव |
|---|---|
| `->nullable()` | कॉलम NULL मान स्वीकार करता है |
| `->default($value)` | DDL में एक DEFAULT clause सेट करता है |
| `->unsigned()` | UNSIGNED लागू करता है (केवल integer टाइप्स) |
| `->unique()` | एक UNIQUE constraint जोड़ता है |
| `->primaryKey()` | कॉलम को प्राइमरी की का हिस्सा चिह्नित करता है |
| `->autoIncrement()` | AUTO_INCREMENT जोड़ता है (केवल integer PKs) |
| `->generated()` | कॉलम DB-computed है; INSERT/UPDATE से बाहर |
| `->comment(string)` | एक column-level DDL कमेंट जोड़ता है |

## PHP 8.1 enum मैपिंग

PHP backed enums (`string` या `int` backing type) स्वाभाविक रूप से डेटाबेस कॉलम पर मैप होते हैं।

### String-backed enum

```php
enum OrderStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Shipped   = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
```

मैपर:

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

हाइड्रेशन:

```php
status: OrderStatus::from($row['status']),
```

निष्कर्षण:

```php
'status' => $entity->status->value,
```

### Int-backed enum

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

मैपर:

```php
ColumnDefinition::smallint('priority')->unsigned()
```

हाइड्रेशन:

```php
priority: Priority::from((int) $row['priority']),
```

### Nullable enum

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

हाइड्रेशन:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
हमेशा `->value` स्टोर करें (जैसे `'pending'`), कभी `->name` नहीं (जैसे `'Pending'`)। Labels को PHP में स्वतंत्र रूप से rename किया जा सकता है; values को बिना migration के नहीं।
:::

## Generated / computed कॉलम

डेटाबेस इंजन द्वारा populated कॉलम (जैसे `GENERATED ALWAYS AS`) को `INSERT` और `UPDATE` स्टेटमेंट से बाहर रखा जाना चाहिए।

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

Weaver `generated` कॉलम को write payloads से स्वचालित रूप से हटाता है। वे `hydrate` में अभी भी दिखाई देते हैं।

## कॉलम aliases

PHP प्रॉपर्टी नाम जब डेटाबेस कॉलम नाम से भिन्न हो तब alias का उपयोग करें:

```php
// PHP property 'email' DB column 'usr_email' पर मैप होती है
ColumnDefinition::string('email')->alias('usr_email')
```

`hydrate` में, array key के रूप में कॉलम नाम (alias) का उपयोग करें:

```php
email: $row['usr_email'],
```

`dehydrate` में, key के रूप में कॉलम नाम लौटाएं:

```php
'usr_email' => $entity->email,
```

## Symfony में मैपर्स पंजीकृत करना

यदि `config/services.yaml` में `autoconfigure: true` सेट है (Symfony डिफ़ॉल्ट), कॉन्फ़िगर किए गए `mapper_paths` में `AbstractMapper` को एक्सटेंड करने वाली कोई भी क्लास स्वचालित रूप से टैग और पंजीकृत होती है — कोई मैन्युअल सेवा परिभाषा की आवश्यकता नहीं।

स्पष्ट पंजीकरण या डिफ़ॉल्ट ओवरराइड के लिए:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
