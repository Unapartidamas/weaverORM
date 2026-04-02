---
id: configuration
title: إعداد Symfony
---

يُعدَّ Weaver ORM عبر `config/packages/weaver.yaml`. تغطي هذه الصفحة كل خيار متاح.

## الإعداد الأدنى

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## مرجع الإعداد الكامل

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # الاتصال الأساسي (الكتابة)
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN له الأولوية على الخيارات الفردية أدناه

        # خيارات فردية (بديل لـ url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # خيارات مجموعة الاتصال (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # لـ MySQL فقط

    # ------------------------------------------------------------------ #
    # نسخة القراءة (اختياري)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # اكتشاف المُعيِّنات
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # أضف مزيداً من المسارات لسياقات محدودة متعددة:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # الهجرات
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # التصحيح والأمان
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # يسجّل جميع الاستعلامات إلى Symfony profiler

    # اكتشاف وتحذير من أنماط استعلامات N+1 في بيئة التطوير
    n1_detector: true               # يعمل فقط عند debug: true

    # ألقِ استثناءً إذا كان SELECT سيعيد أكثر من N صف.
    # يحمي من عمليات مسح الجدول الكاملة العرضية في الإنتاج.
    # اضبط على 0 لتعطيله.
    max_rows_safety_limit: 5000
```

## برامج تشغيل الاتصال

### pdo_pgsql — PostgreSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/myapp?serverVersion=16&charset=utf8"
```

### pdo_mysql — MySQL / MariaDB

```yaml
weaver_orm:
    connection:
        driver: pdo_mysql
        url: '%env(DATABASE_URL)%'
```

```dotenv
DATABASE_URL="mysql://app:secret@db:3306/myapp?serverVersion=8.0&charset=utf8mb4"
```

### pdo_sqlite — SQLite (اختبار / مدمج)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# في الذاكرة (مفيد لاختبارات التكامل):
DATABASE_URL="sqlite:///:memory:"

# مستند إلى ملف:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — محرك PyroSQL التحليلي

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL كاتصال قراءة مخصص للاستعلامات التحليلية
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## متغيرات البيئة

إعداد نموذجي لـ `.env` / `.env.local`:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (لا يُودَع أبداً في نظام التحكم بالإصدارات)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# نسخة القراءة (اختياري)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## إعداد نسخة القراءة

عند تعريف `read_connection`، يوجّه Weaver تلقائياً استعلامات `SELECT` إلى اتصال القراءة واستعلامات `INSERT` / `UPDATE` / `DELETE` إلى الاتصال الأساسي.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

لإجبار الاستعلام على الاتصال الأساسي (مثلاً، مباشرةً بعد الكتابة)، استخدم `->onPrimary()`:

```php
// يقرأ دائماً من الأساسي، متجاوزاً النسخة المتماثلة
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## وضع التصحيح وكاشف N+1

عند تفعيل `debug: true` (الإعداد الافتراضي لـ Symfony في بيئة `dev`)، يقوم Weaver بـ:

- تسجيل كل استعلام SQL مع ارتباطاته إلى Symfony Web Profiler.
- تفعيل **كاشف N+1** عند تفعيل `n1_detector: true`. يفحص الكاشف أنماط التحميل المسبق ويُصدر تحذيراً في الـ profiler إذا اكتشف وصولاً إلى علاقة على كيانات متعددة دون تحميلها مسبقاً.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # أكثر صرامة في التطوير
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## سياقات محدودة متعددة

إذا كان تطبيقك يستخدم قواعد بيانات متعددة أو تريد فصلاً صارماً بين السياقات المحدودة، يمكنك تسجيل مجموعات اتصال متعددة باستخدام وسم الخدمات في Symfony مباشرةً. انظر [دليل الإعداد المتقدم](#) للتفاصيل.
