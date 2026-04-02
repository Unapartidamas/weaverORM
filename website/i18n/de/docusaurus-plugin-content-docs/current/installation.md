---
id: installation
title: Installation
---

## Anforderungen

Überprüfen Sie vor der Installation von Weaver ORM, ob Ihre Umgebung die Mindestanforderungen erfüllt:

| Anforderung | Version |
|---|---|
| PHP | **8.4** oder höher |
| Symfony | **7.0** oder höher |
| doctrine/dbal | 4.0 (wird automatisch hinzugezogen) |
| Datenbank | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Schritt 1 — Installation über Composer

```bash
docker compose exec app composer require weaver/orm
```

Dies lädt Folgendes herunter:

- `weaver/orm` — der Kern-Mapper, Query Builder und Unit of Work
- `weaver/orm-bundle` — das Symfony-Bundle (automatisch von Symfony Flex registriert)
- `doctrine/dbal ^4.0` — wird als Verbindungs- und Schema-Abstraktionsschicht verwendet (nicht Doctrine ORM)

:::info Docker
Alle Befehle in dieser Dokumentation setzen voraus, dass Sie innerhalb eines Docker-Containers arbeiten. Passen Sie den Service-Namen (`app`) an Ihre `docker-compose.yml` an.
:::

## Schritt 2 — Bundle registrieren

Wenn Sie Symfony Flex verwenden, wird das Bundle automatisch registriert. Falls nicht, fügen Sie es manuell zu `config/bundles.php` hinzu:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... andere Bundles ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Schritt 3 — Konfigurationsdatei erstellen

Erstellen Sie `config/packages/weaver.yaml` mit einer minimalen Verbindungskonfiguration:

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

Fügen Sie die Datenbank-URL zu Ihrer `.env`-Datei hinzu (oder `.env.local` für lokale Überschreibungen):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Schritt 4 — Installation überprüfen

```bash
docker compose exec app bin/console weaver:info
```

Erwartete Ausgabe:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Unterstützte Datenbanktreiber

| Treiber | Datenbank |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (analytisch, leseoptimiert) |

## Optionale Pakete

### PyroSQL (analytische Read-Replica)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Aktiviert eine hochleistungsfähige In-Process-Analyse-Engine als sekundäre Verbindung für leseintensive Abfragen und Berichte.

### MongoDB-Dokumentmapper

```bash
docker compose exec app composer require mongodb/mongodb
```

Erfordert die PHP-Erweiterung `ext-mongodb`. Aktiviert `AbstractDocumentMapper` für dokumentorientierte Speicherung neben dem relationalen Mapper.

### Symfony Messenger-Integration

```bash
docker compose exec app composer require symfony/messenger
```

Aktiviert das Outbox-Muster und asynchrone Domain-Event-Veröffentlichung aus Entity-Lifecycle-Hooks heraus.

### Caching von Abfrageergebnissen

```bash
docker compose exec app composer require symfony/cache
```

Aktiviert `->cache(ttl: 60)` in Query-Builder-Ketten, um hydratisierte Ergebnisse in einem PSR-6-Cache-Pool zu speichern.
