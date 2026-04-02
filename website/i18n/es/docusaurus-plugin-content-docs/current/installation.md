---
id: installation
title: Instalación
---

## Requisitos

Antes de instalar Weaver ORM, verifica que tu entorno cumple los requisitos mínimos:

| Requisito | Versión |
|---|---|
| PHP | **8.4** o superior |
| Symfony | **7.0** o superior |
| doctrine/dbal | 4.0 (incluido automáticamente) |
| Base de datos | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Paso 1 — Instalar mediante Composer

```bash
docker compose exec app composer require weaver/orm
```

Esto incluye:

- `weaver/orm` — el mapper central, el query builder y la unidad de trabajo
- `weaver/orm-bundle` — el bundle de Symfony (registrado automáticamente por Symfony Flex)
- `doctrine/dbal ^4.0` — utilizado como capa de conexión y abstracción de esquema (no Doctrine ORM)

:::info Docker
Todos los comandos en esta documentación asumen que estás ejecutando dentro de un contenedor Docker. Ajusta el nombre del servicio (`app`) para que coincida con tu `docker-compose.yml`.
:::

## Paso 2 — Registrar el bundle

Si usas Symfony Flex, el bundle se registra automáticamente. Si no, añádelo manualmente en `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... otros bundles ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Paso 3 — Crear el archivo de configuración

Crea `config/packages/weaver.yaml` con una configuración de conexión mínima:

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

Añade la URL de la base de datos a tu archivo `.env` (o `.env.local` para sobreescrituras locales):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Paso 4 — Verificar la instalación

```bash
docker compose exec app bin/console weaver:info
```

Salida esperada:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Drivers de base de datos compatibles

| Driver | Base de datos |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (analítico, optimizado para lectura) |

## Paquetes opcionales

### PyroSQL (réplica de lectura analítica)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Habilita un motor analítico de alto rendimiento en proceso como conexión secundaria para consultas intensivas de lectura y reportes.

### Mapper de documentos MongoDB

```bash
docker compose exec app composer require mongodb/mongodb
```

Requiere la extensión PHP `ext-mongodb`. Habilita `AbstractDocumentMapper` para almacenamiento orientado a documentos junto al mapper relacional.

### Integración con Symfony Messenger

```bash
docker compose exec app composer require symfony/messenger
```

Habilita el patrón outbox y la publicación asíncrona de eventos de dominio desde los hooks del ciclo de vida de las entidades.

### Caché de resultados de consultas

```bash
docker compose exec app composer require symfony/cache
```

Habilita `->cache(ttl: 60)` en cadenas del query builder para almacenar resultados hidratados en un pool de caché PSR-6.
