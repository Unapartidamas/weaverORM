---
id: entity-mapping
title: تعيين الكيانات
---

يفصل Weaver ORM كائنات النطاق عن بيانات وصف الاستمرار بوضع جميع معلومات التعيين في **صف مُعيِّن** مخصص. تغطي هذه الصفحة كل جانب من جوانب إعداد المُعيِّن.

## لماذا المُعيِّنات بدلاً من الخصائص؟

يضع Doctrine ORM بيانات وصف التعيين مباشرةً على صف الكيان عبر خصائص PHP 8:

```php
// نهج Doctrine — الكيان يعرف عن قاعدة البيانات
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

يُبقيها Weaver مفصولة بصرامة:

```
صف الكيان        →  كائن PHP عادي، بدون اعتماديات ORM
صف المُعيِّن      →  كل معرفة الاستمرار تعيش هنا
```

الفوائد:
- **لا انعكاس في وقت التشغيل.** المُعيِّن هو PHP عادي يعيد مصفوفات وقيماً عددية.
- **لا صفوف وكيل.** لا حاجة لتوليد الكود على القرص.
- **آمن للعمال.** المُعيِّنات لا تحمل حالة لكل طلب.
- **قابل للاختبار منفصلاً.** أنشئ مُعيِّناً وافحصه في اختبار وحدة دون تشغيل Symfony.
- **قابل للبحث بالكامل.** كل اسم عمود، كل نوع، كل خيار يظهر في نص عادي ويظهر في `git diff`.

## المُعيِّن مقابل الكيان: المسؤوليات

| الاهتمام | يعيش في |
|---|---|
| منطق الأعمال، والثوابت | صف الكيان |
| الخصائص وأنواع PHP | صف الكيان |
| اسم الجدول والمخطط | المُعيِّن |
| أسماء الأعمدة والأنواع والخيارات | المُعيِّن |
| الفهارس والقيود | المُعيِّن |
| الترطيب (صف → كيان) | المُعيِّن |
| الاستخراج (كيان → صف) | المُعيِّن |
| العلاقات | المُعيِّن |

## تعريف الكيان الأساسي

الكيان هو أي صف PHP. لا يمتد من أي شيء، ولا يُنفّذ أي شيء، ولا يستورد أي شيء من `Weaver\ORM`.

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

يمكن للكيان أن يكون:
- **ثابتاً** (موصى به) — طرق التحويل تعيد نسخاً جديدة
- **قابلاً للتغيير** — الخصائص العامة أو الضوابط مقبولة
- **مجرداً** — لهيكليات الوراثة

## AbstractMapper

كل كيان يحتاج إلى مُعيِّن واحد بالضبط. أنشئ صفاً يمتد من `Weaver\ORM\Mapping\AbstractMapper` ونفّذ الطرق المطلوبة.

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

### طرق المُعيِّن المطلوبة

| الطريقة | الغرض |
|---|---|
| `table(): string` | اسم الجدول في قاعدة البيانات |
| `primaryKey(): string\|array` | اسم(أسماء) عمود المفتاح الأساسي |
| `schema(): SchemaDefinition` | جميع تعريفات الأعمدة لـ DDL والهجرات |
| `hydrate(array $row): object` | بناء كيان من صف قاعدة بيانات خام |
| `dehydrate(object $entity): array` | تسلسل كيان إلى مصفوفة عمود => قيمة |

### طرق المُعيِّن الاختيارية

| الطريقة | الغرض |
|---|---|
| `readOnly(): bool` | أعِد `true` للكيانات المدعومة بالعرض (بدون INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | يُستخدم لوراثة الجدول المفرد (Single Table Inheritance) |
| `discriminatorMap(): array` | يُستخدم لوراثة الجدول المفرد |
| `parentMapper(): ?string` | يُستخدم لوراثة جدول الصف (Class Table Inheritance) |

## أنواع الأعمدة

تستخدم جميع تعريفات الأعمدة طرق المصنع الثابتة على `ColumnDefinition`. كل طريقة تعيد مثيل `ColumnDefinition` مع واجهة برمجة إعداد سلسة.

### string

يُعيَّن إلى `VARCHAR(n)`. الطول الافتراضي هو 255.

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer، bigint، smallint

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float و decimal

استخدم `decimal` للقيم المالية؛ و`float` للإحداثيات والقياسات.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

رطّب `decimal` كسلسلة للحفاظ على الدقة:

```php
price: $row['price'],  // احتفظ به كسلسلة، مرره إلى كائن قيمة Money
```

### boolean

يُعيَّن إلى `TINYINT(1)` في MySQL، و`BOOLEAN` في PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

اصطبغ دائماً بشكل صريح في `hydrate`:

```php
isActive: (bool) $row['is_active'],
```

### datetime، date، time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

يعيد `datetime` كائن `\DateTime` قابلاً للتغيير. فضّل `datetimeImmutable` للكود الجديد:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

الترطيب:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

الاستخراج:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

يُعيَّن إلى `JSON` (MySQL 5.7.8+، PostgreSQL، SQLite). أنت تتحكم في الترميز/فك الترميز في `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

الترطيب:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

الاستخراج:

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text، blob

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid (UUID كـ CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## أنواع المفتاح الأساسي

### عدد صحيح تزايدي تلقائي

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

يحذف Weaver `id` من `INSERT` عندما تكون القيمة `null` ويقرأ القيمة المولّدة تلقائياً.

### UUID v4 (عشوائي)

```php
ColumnDefinition::guid('id')->primaryKey()
```

ولّد UUID في طريقة مصنع الكيان قبل الاستمرار:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (مرتّب زمنياً، موصى به)

يتضمن UUID v7 بادئة طابع زمني بالميلي ثانية، مما يجعل المفاتيح تزايدية رتيبة ويقلل بشكل كبير من انقسامات صفحات B-tree مقارنةً بـ UUID العشوائية.

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

### مفتاح سلسلة طبيعي

عندما يكون مفتاح الأعمال فريداً بطبيعته (رمز البلد، رمز العملة، slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### مفتاح أساسي مركّب

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## خيارات الأعمدة

جميع الخيارات متاحة كطرق سلسة على `ColumnDefinition`:

| الطريقة | التأثير |
|---|---|
| `->nullable()` | العمود يقبل قيم NULL |
| `->default($value)` | يضع جملة DEFAULT في DDL |
| `->unsigned()` | يُطبّق UNSIGNED (لأنواع الأعداد الصحيحة فقط) |
| `->unique()` | يضيف قيداً UNIQUE |
| `->primaryKey()` | يُعيّن العمود كجزء من المفتاح الأساسي |
| `->autoIncrement()` | يضيف AUTO_INCREMENT (لـ PK الأعداد الصحيحة فقط) |
| `->generated()` | العمود محسوب من قاعدة البيانات؛ مستبعد من INSERT/UPDATE |
| `->comment(string)` | يضيف تعليق DDL على مستوى العمود |

## تعيين enum في PHP 8.1

تُعيَّن enums المدعومة في PHP (`string` أو نوع دعم `int`) بشكل طبيعي إلى أعمدة قاعدة البيانات.

### enum مدعوم بسلسلة

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

المُعيِّن:

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

الترطيب:

```php
status: OrderStatus::from($row['status']),
```

الاستخراج:

```php
'status' => $entity->status->value,
```

### enum مدعوم بعدد صحيح

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

المُعيِّن:

```php
ColumnDefinition::smallint('priority')->unsigned()
```

الترطيب:

```php
priority: Priority::from((int) $row['priority']),
```

### enum قابل للإلغاء

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

الترطيب:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
خزّن دائماً `->value` (مثلاً `'pending'`)، وليس `->name` (مثلاً `'Pending'`). يمكن إعادة تسمية التسميات بحرية في PHP؛ القيم لا يمكن ذلك بدون هجرة.
:::

## الأعمدة المولّدة/المحسوبة

يجب استبعاد الأعمدة التي يملأها محرك قاعدة البيانات (مثلاً `GENERATED ALWAYS AS`) من جملتي `INSERT` و`UPDATE`.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

يزيل Weaver الأعمدة `generated` من حمولات الكتابة تلقائياً. لا تزال تظهر في `hydrate`.

## أسماء مستعارة للأعمدة

استخدم اسماً مستعاراً عندما يختلف اسم خاصية PHP عن اسم عمود قاعدة البيانات:

```php
// خاصية PHP 'email' تُعيَّن إلى عمود قاعدة البيانات 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

في `hydrate`، استخدم اسم العمود (الاسم المستعار) كمفتاح المصفوفة:

```php
email: $row['usr_email'],
```

في `dehydrate`، أعِد اسم العمود كمفتاح:

```php
'usr_email' => $entity->email,
```

## تسجيل المُعيِّنات في Symfony

إذا كان `autoconfigure: true` محدداً في `config/services.yaml` (الإعداد الافتراضي لـ Symfony)، فإن أي صف يمتد من `AbstractMapper` في `mapper_paths` المُعدّة يُوسَم ويُسجَّل تلقائياً — لا حاجة لتعريف خدمة يدوي.

للتسجيل الصريح أو لتجاوز الإعدادات الافتراضية:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
