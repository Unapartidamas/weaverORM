---
id: configuration
title: Symfony कॉन्फ़िगरेशन
---

Weaver ORM को `config/packages/weaver.yaml` के माध्यम से कॉन्फ़िगर किया जाता है। यह पृष्ठ सभी उपलब्ध विकल्पों को कवर करता है।

## न्यूनतम कॉन्फ़िगरेशन

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## पूर्ण कॉन्फ़िगरेशन संदर्भ

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # प्राथमिक (write) कनेक्शन
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN नीचे के अलग-अलग विकल्पों की तुलना में प्राथमिकता लेता है

        # अलग-अलग विकल्प (url: का विकल्प)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # कनेक्शन पूल विकल्प (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # केवल MySQL

    # ------------------------------------------------------------------ #
    # रीड रेप्लिका (वैकल्पिक)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # मैपर खोज
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # कई bounded contexts के लिए अधिक पाथ जोड़ें:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # माइग्रेशन
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # डीबग और सुरक्षा
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Symfony profiler में सभी क्वेरीज़ लॉग करता है

    # डेवलपमेंट में N+1 क्वेरी पैटर्न का पता लगाएं और चेतावनी दें
    n1_detector: true               # केवल तभी सक्रिय जब debug: true हो

    # यदि SELECT N से अधिक पंक्तियाँ लौटाएगा तो अपवाद फेंकें।
    # प्रोडक्शन में आकस्मिक full-table स्कैन से बचाता है।
    # अक्षम करने के लिए 0 पर सेट करें।
    max_rows_safety_limit: 5000
```

## कनेक्शन ड्राइवर

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

### pdo_sqlite — SQLite (परीक्षण / एम्बेडेड)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# इन-मेमोरी (integration tests के लिए उपयोगी):
DATABASE_URL="sqlite:///:memory:"

# फ़ाइल-आधारित:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — PyroSQL विश्लेषणात्मक इंजन

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # विश्लेषणात्मक क्वेरीज़ के लिए समर्पित रीड कनेक्शन के रूप में PyroSQL
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## एनवायरनमेंट वेरिएबल

एक विशिष्ट `.env` / `.env.local` सेटअप:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (VCS में कभी commit नहीं)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# रीड रेप्लिका (वैकल्पिक)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## रीड रेप्लिका कॉन्फ़िगरेशन

जब `read_connection` परिभाषित होता है, Weaver स्वचालित रूप से `SELECT` क्वेरीज़ को रीड कनेक्शन पर और `INSERT` / `UPDATE` / `DELETE` क्वेरीज़ को प्राथमिक कनेक्शन पर रूट करता है।

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

किसी क्वेरी को प्राथमिक कनेक्शन पर मजबूर करने के लिए (जैसे write के तुरंत बाद), `->onPrimary()` का उपयोग करें:

```php
// हमेशा primary से पढ़ता है, replica को बाईपास करता है
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## डीबग मोड और N+1 डिटेक्टर

जब `debug: true` (`dev` एनवायरनमेंट में Symfony डिफ़ॉल्ट), Weaver:

- Symfony Web Profiler में अपनी bindings के साथ हर SQL क्वेरी लॉग करता है।
- जब `n1_detector: true` हो तो **N+1 डिटेक्टर** सक्रिय करता है। डिटेक्टर eager-loading पैटर्न की जांच करता है और profiler में चेतावनी देता है यदि यह पता लगाता है कि कोई रिलेशन पूर्व-लोड किए बिना कई एंटिटीज़ पर एक्सेस किया गया था।

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # dev में अधिक सख्त
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## कई bounded contexts

यदि आपका अनुप्रयोग कई डेटाबेस का उपयोग करता है या आप bounded contexts के बीच सख्त अलगाव चाहते हैं, तो आप Symfony के सेवा टैगिंग का सीधे उपयोग करके कई कनेक्शन सेट पंजीकृत कर सकते हैं। विवरण के लिए [उन्नत कॉन्फ़िगरेशन गाइड](#) देखें।
