---
id: installation
title: Installation
---

## Prérequis

Avant d'installer Weaver ORM, vérifiez que votre environnement satisfait les exigences minimales :

| Prérequis | Version |
|---|---|
| PHP | **8.4** ou supérieur |
| Symfony | **7.0** ou supérieur |
| doctrine/dbal | 4.0 (installé automatiquement) |
| Base de données | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Étape 1 — Installation via Composer

```bash
docker compose exec app composer require weaver/orm
```

Cela installe :

- `weaver/orm` — le mapper principal, le query builder et l'unité de travail
- `weaver/orm-bundle` — le bundle Symfony (enregistré automatiquement par Symfony Flex)
- `doctrine/dbal ^4.0` — utilisé comme couche de connexion et d'abstraction de schéma (pas Doctrine ORM)

:::info Docker
Toutes les commandes de cette documentation supposent que vous exécutez à l'intérieur d'un conteneur Docker. Adaptez le nom du service (`app`) pour correspondre à votre `docker-compose.yml`.
:::

## Étape 2 — Enregistrement du bundle

Si vous utilisez Symfony Flex, le bundle est enregistré automatiquement. Sinon, ajoutez-le manuellement à `config/bundles.php` :

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... autres bundles ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Étape 3 — Création du fichier de configuration

Créez `config/packages/weaver.yaml` avec une configuration de connexion minimale :

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

Ajoutez l'URL de base de données à votre fichier `.env` (ou `.env.local` pour les surcharges locales) :

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Étape 4 — Vérification de l'installation

```bash
docker compose exec app bin/console weaver:info
```

Résultat attendu :

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Pilotes de base de données supportés

| Pilote | Base de données |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (analytique, optimisé en lecture) |

## Paquets optionnels

### PyroSQL (réplica de lecture analytique)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Active un moteur analytique haute performance en cours de processus comme connexion secondaire pour les requêtes à forte lecture et les rapports.

### Mappeur de documents MongoDB

```bash
docker compose exec app composer require mongodb/mongodb
```

Nécessite l'extension PHP `ext-mongodb`. Active `AbstractDocumentMapper` pour le stockage orienté document aux côtés du mappeur relationnel.

### Intégration Symfony Messenger

```bash
docker compose exec app composer require symfony/messenger
```

Active le patron outbox et la publication d'événements de domaine asynchrones depuis les hooks du cycle de vie des entités.

### Mise en cache des résultats de requêtes

```bash
docker compose exec app composer require symfony/cache
```

Active `->cache(ttl: 60)` sur les chaînes du query builder pour stocker les résultats hydratés dans un pool de cache PSR-6.
