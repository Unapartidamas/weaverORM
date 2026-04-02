---
id: installation
title: इंस्टॉलेशन
---

## आवश्यकताएं

Weaver ORM इंस्टॉल करने से पहले, सुनिश्चित करें कि आपका परिवेश न्यूनतम आवश्यकताओं को पूरा करता है:

| आवश्यकता | संस्करण |
|---|---|
| PHP | **8.4** या उच्चतर |
| Symfony | **7.0** या उच्चतर |
| doctrine/dbal | 4.0 (स्वचालित रूप से खिंचा जाता है) |
| डेटाबेस | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## चरण 1 — Composer के माध्यम से इंस्टॉल करें

```bash
docker compose exec app composer require weaver/orm
```

यह निम्नलिखित खींचता है:

- `weaver/orm` — मुख्य मैपर, क्वेरी बिल्डर, और यूनिट-ऑफ-वर्क
- `weaver/orm-bundle` — Symfony बंडल (Symfony Flex द्वारा स्वचालित रूप से पंजीकृत)
- `doctrine/dbal ^4.0` — कनेक्शन और स्कीमा अब्स्ट्रेक्शन लेयर के रूप में उपयोग किया जाता है (Doctrine ORM नहीं)

:::info Docker
इस दस्तावेज़ीकरण में सभी कमांड मानते हैं कि आप Docker कंटेनर के अंदर चल रहे हैं। सेवा नाम (`app`) को अपने `docker-compose.yml` से मिलाने के लिए समायोजित करें।
:::

## चरण 2 — बंडल पंजीकृत करें

यदि आप Symfony Flex का उपयोग करते हैं तो बंडल स्वचालित रूप से पंजीकृत होता है। यदि नहीं, तो इसे `config/bundles.php` में मैन्युअल रूप से जोड़ें:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... अन्य बंडल ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## चरण 3 — कॉन्फ़िगरेशन फ़ाइल बनाएं

न्यूनतम कनेक्शन कॉन्फ़िगरेशन के साथ `config/packages/weaver.yaml` बनाएं:

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

डेटाबेस URL को अपनी `.env` फ़ाइल (या स्थानीय ओवरराइड के लिए `.env.local`) में जोड़ें:

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## चरण 4 — इंस्टॉलेशन सत्यापित करें

```bash
docker compose exec app bin/console weaver:info
```

अपेक्षित आउटपुट:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## समर्थित डेटाबेस ड्राइवर

| ड्राइवर | डेटाबेस |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (विश्लेषणात्मक, रीड-ऑप्टिमाइज़्ड) |

## वैकल्पिक पैकेज

### PyroSQL (विश्लेषणात्मक रीड रेप्लिका)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

रीड-हेवी क्वेरीज़ और रिपोर्टिंग के लिए द्वितीयक कनेक्शन के रूप में एक उच्च-प्रदर्शन इन-प्रक्रिया विश्लेषणात्मक इंजन सक्षम करता है।

### MongoDB दस्तावेज़ मैपर

```bash
docker compose exec app composer require mongodb/mongodb
```

`ext-mongodb` PHP एक्सटेंशन की आवश्यकता है। रिलेशनल मैपर के साथ document-oriented स्टोरेज के लिए `AbstractDocumentMapper` सक्षम करता है।

### Symfony Messenger एकीकरण

```bash
docker compose exec app composer require symfony/messenger
```

एंटिटी लाइफसाइकिल हुक्स के भीतर से आउटबॉक्स पैटर्न और असिंक्रोनस डोमेन इवेंट पब्लिशिंग सक्षम करता है।

### क्वेरी परिणाम कैशिंग

```bash
docker compose exec app composer require symfony/cache
```

PSR-6 कैश पूल में हाइड्रेटेड परिणामों को स्टोर करने के लिए क्वेरी बिल्डर चेन पर `->cache(ttl: 60)` सक्षम करता है।
