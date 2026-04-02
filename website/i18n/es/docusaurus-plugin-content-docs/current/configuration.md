---
id: configuration
title: Configuración de Symfony
---

Weaver ORM se configura mediante `config/packages/weaver.yaml`. Esta página cubre todas las opciones disponibles.

## Configuración mínima

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Referencia de configuración completa

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Conexión primaria (escritura)
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # El DSN tiene prioridad sobre las opciones individuales

        # Opciones individuales (alternativa a url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Opciones de pool de conexiones (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # Solo MySQL

    # ------------------------------------------------------------------ #
    # Réplica de lectura (opcional)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Descubrimiento de mappers
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Añade más rutas para múltiples contextos delimitados:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Migraciones
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Depuración y seguridad
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Registra todas las consultas en el profiler de Symfony

    # Detecta y avisa sobre patrones de consultas N+1 en desarrollo
    n1_detector: true               # Solo activo cuando debug: true

    # Lanza una excepción si un SELECT devolvería más de N filas.
    # Protege contra escaneos accidentales de tabla completa en producción.
    # Establece en 0 para deshabilitar.
    max_rows_safety_limit: 5000
```

## Drivers de conexión

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

### pdo_sqlite — SQLite (pruebas / embebido)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# En memoria (útil para pruebas de integración):
DATABASE_URL="sqlite:///:memory:"

# Basado en archivo:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — Motor analítico PyroSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL como conexión de lectura dedicada para consultas analíticas
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## Variables de entorno

Una configuración típica de `.env` / `.env.local`:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (nunca se sube al control de versiones)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Réplica de lectura (opcional)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Configuración de réplica de lectura

Cuando se define `read_connection`, Weaver enruta automáticamente las consultas `SELECT` a la conexión de lectura y las consultas `INSERT` / `UPDATE` / `DELETE` a la conexión primaria.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

Para forzar una consulta en la conexión primaria (por ejemplo, inmediatamente después de una escritura), usa `->onPrimary()`:

```php
// Siempre lee desde primario, omitiendo la réplica
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Modo depuración y el detector N+1

Cuando `debug: true` (el valor predeterminado de Symfony en el entorno `dev`), Weaver:

- Registra cada consulta SQL con sus bindings en el Web Profiler de Symfony.
- Activa el **detector N+1** cuando `n1_detector: true`. El detector inspecciona los patrones de carga anticipada y emite una advertencia en el profiler si detecta que se accedió a una relación en múltiples entidades sin haberla pre-cargado.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # más estricto en desarrollo
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Múltiples contextos delimitados

Si tu aplicación usa múltiples bases de datos o quieres una separación estricta entre contextos delimitados, puedes registrar múltiples conjuntos de conexiones usando directamente el sistema de etiquetas de servicios de Symfony. Consulta la [guía de configuración avanzada](#) para más detalles.
