---
id: installation
title: التثبيت
---

## المتطلبات

قبل تثبيت Weaver ORM، تحقق من أن بيئتك تستوفي الحد الأدنى من المتطلبات:

| المتطلب | الإصدار |
|---|---|
| PHP | **8.4** أو أعلى |
| Symfony | **7.0** أو أعلى |
| doctrine/dbal | 4.0 (يُضاف تلقائياً) |
| قاعدة البيانات | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## الخطوة 1 — التثبيت عبر Composer

```bash
docker compose exec app composer require weaver/orm
```

يشمل هذا:

- `weaver/orm` — المُعيِّن الأساسي ومنشئ الاستعلامات ووحدة العمل
- `weaver/orm-bundle` — حزمة Symfony (تُسجَّل تلقائياً بواسطة Symfony Flex)
- `doctrine/dbal ^4.0` — يُستخدم كطبقة اتصال ومخطط مجرد (ليس Doctrine ORM)

:::info Docker
تفترض جميع الأوامر في هذا التوثيق أنك تعمل داخل حاوية Docker. اضبط اسم الخدمة (`app`) ليتوافق مع ملف `docker-compose.yml`.
:::

## الخطوة 2 — تسجيل الحزمة

إذا كنت تستخدم Symfony Flex، تُسجَّل الحزمة تلقائياً. إذا لم يكن كذلك، أضفها يدوياً إلى `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... حزم أخرى ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## الخطوة 3 — إنشاء ملف الإعداد

أنشئ `config/packages/weaver.yaml` بإعداد اتصال أدنى:

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'

    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'
```

أضف رابط قاعدة البيانات إلى ملف `.env` (أو `.env.local` للتجاوزات المحلية):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## الخطوة 4 — التحقق من التثبيت

```bash
docker compose exec app bin/console weaver:info
```

المخرجات المتوقعة:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## برامج تشغيل قاعدة البيانات المدعومة

| المشغّل | قاعدة البيانات |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (تحليلي، محسّن للقراءة) |

## الحزم الاختيارية

### PyroSQL (نسخة قراءة تحليلية)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

يُمكّن محرك تحليلي داخلي عالي الأداء كاتصال ثانوي للاستعلامات الكثيفة في القراءة والتقارير.

### مُعيِّن وثيقة MongoDB

```bash
docker compose exec app composer require mongodb/mongodb
```

يتطلب إضافة PHP `ext-mongodb`. يُمكّن `AbstractDocumentMapper` للتخزين الموجه بالوثائق جنباً إلى جنب مع المُعيِّن العلائقي.

### تكامل Symfony Messenger

```bash
docker compose exec app composer require symfony/messenger
```

يُمكّن نمط صندوق البريد الصادر ونشر أحداث النطاق غير المتزامن من داخل خطافات دورة حياة الكيان.

### التخزين المؤقت لنتائج الاستعلام

```bash
docker compose exec app composer require symfony/cache
```

يُمكّن `->cache(ttl: 60)` على سلاسل منشئ الاستعلامات لتخزين النتائج المُرطّبة في مجموعة ذاكرة تخزين مؤقت PSR-6.
