---
id: configuration
title: Configuration Symfony
---

Weaver ORM est configuré via `config/packages/weaver.yaml`. Cette page couvre toutes les options disponibles.

## Configuration minimale

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Référence de configuration complète

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Connexion principale (écriture)
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # Le DSN a priorité sur les options individuelles ci-dessous

        # Options individuelles (alternative à url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Options du pool de connexions (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # MySQL uniquement

    # ------------------------------------------------------------------ #
    # Réplica de lecture (optionnel)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Découverte des mappers
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Ajoutez d'autres chemins pour plusieurs contextes délimités :
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Migrations
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Débogage et sécurité
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Journalise toutes les requêtes dans le profiler Symfony

    # Détecte et avertit des schémas de requêtes N+1 en développement
    n1_detector: true               # Actif uniquement quand debug: true

    # Lève une exception si un SELECT renverrait plus de N lignes.
    # Protège contre les scans complets accidentels de table en production.
    # Mettre à 0 pour désactiver.
    max_rows_safety_limit: 5000
```

## Pilotes de connexion

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

### pdo_sqlite — SQLite (tests / embarqué)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# En mémoire (utile pour les tests d'intégration) :
DATABASE_URL="sqlite:///:memory:"

# Basé sur un fichier :
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — moteur analytique PyroSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL comme connexion de lecture dédiée pour les requêtes analytiques
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## Variables d'environnement

Une configuration typique `.env` / `.env.local` :

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (jamais commité dans le VCS)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Réplica de lecture (optionnel)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Configuration du réplica de lecture

Quand `read_connection` est défini, Weaver route automatiquement les requêtes `SELECT` vers la connexion de lecture et les requêtes `INSERT` / `UPDATE` / `DELETE` vers la connexion principale.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

Pour forcer une requête sur la connexion principale (par exemple, immédiatement après une écriture), utilisez `->onPrimary()` :

```php
// Lit toujours depuis le primaire, en contournant le réplica
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Mode débogage et le détecteur N+1

Quand `debug: true` (la valeur par défaut de Symfony en environnement `dev`), Weaver :

- Journalise chaque requête SQL avec ses liaisons dans le Web Profiler Symfony.
- Active le **détecteur N+1** quand `n1_detector: true`. Le détecteur inspecte les schémas de chargement anticipé et émet un avertissement dans le profiler s'il détecte qu'une relation a été accédée sur plusieurs entités sans avoir été pré-chargée.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # plus strict en dev
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Contextes délimités multiples

Si votre application utilise plusieurs bases de données ou si vous souhaitez une séparation stricte entre les contextes délimités, vous pouvez enregistrer plusieurs ensembles de connexions en utilisant directement le tagging de services de Symfony. Consultez le [guide de configuration avancée](#) pour plus de détails.
