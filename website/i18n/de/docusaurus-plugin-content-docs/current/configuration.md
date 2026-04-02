---
id: configuration
title: Symfony-Konfiguration
---

Weaver ORM wird über `config/packages/weaver.yaml` konfiguriert. Diese Seite behandelt alle verfügbaren Optionen.

## Minimale Konfiguration

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Vollständige Konfigurationsreferenz

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Primäre (Schreib-)Verbindung
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN hat Vorrang vor den einzelnen Optionen unten

        # Einzelne Optionen (Alternative zu url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Verbindungspool-Optionen (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # Nur MySQL

    # ------------------------------------------------------------------ #
    # Read-Replica (optional)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Mapper-Erkennung
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Weitere Pfade für mehrere Bounded Contexts hinzufügen:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Migrationen
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Debug & Sicherheit
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Protokolliert alle Abfragen im Symfony-Profiler

    # N+1-Abfragemuster in der Entwicklung erkennen und warnen
    n1_detector: true               # Nur aktiv wenn debug: true

    # Eine Ausnahme werfen, wenn ein SELECT mehr als N Zeilen zurückgeben würde.
    # Schützt vor versehentlichen vollständigen Tabellenscans in der Produktion.
    # Auf 0 setzen zum Deaktivieren.
    max_rows_safety_limit: 5000
```

## Verbindungstreiber

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

### pdo_sqlite — SQLite (Testen / Embedded)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# Im Arbeitsspeicher (nützlich für Integrationstests):
DATABASE_URL="sqlite:///:memory:"

# Dateibasiert:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — PyroSQL-Analyse-Engine

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL als dedizierte Read-Verbindung für analytische Abfragen
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## Umgebungsvariablen

Eine typische `.env` / `.env.local`-Konfiguration:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (niemals in die Versionskontrolle einzuchecken)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Read-Replica (optional)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Read-Replica-Konfiguration

Wenn `read_connection` definiert ist, leitet Weaver `SELECT`-Abfragen automatisch an die Leseverbindung und `INSERT` / `UPDATE` / `DELETE`-Abfragen an die primäre Verbindung weiter.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

Um eine Abfrage auf die primäre Verbindung zu erzwingen (z. B. unmittelbar nach einem Schreibvorgang), verwenden Sie `->onPrimary()`:

```php
// Liest immer von der primären Verbindung, umgeht die Replica
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Debug-Modus und der N+1-Detektor

Wenn `debug: true` ist (Symfony-Standard in der `dev`-Umgebung):

- Protokolliert jede SQL-Abfrage mit ihren Bindungen im Symfony Web Profiler.
- Aktiviert den **N+1-Detektor** wenn `n1_detector: true`. Der Detektor überprüft Eager-Loading-Muster und gibt eine Warnung im Profiler aus, wenn festgestellt wird, dass auf eine Beziehung bei mehreren Entities zugegriffen wurde, ohne sie vorab zu laden.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # strenger in der Entwicklung
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Mehrere Bounded Contexts

Wenn Ihre Anwendung mehrere Datenbanken verwendet oder Sie eine strikte Trennung zwischen Bounded Contexts wünschen, können Sie mehrere Verbindungssets durch direktes Symfony-Service-Tagging registrieren. Weitere Details finden Sie im [erweiterten Konfigurationsleitfaden](#).
