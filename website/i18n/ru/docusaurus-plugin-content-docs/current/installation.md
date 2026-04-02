---
id: installation
title: Установка
---

## Требования

Перед установкой Weaver ORM убедитесь, что ваша среда соответствует минимальным требованиям:

| Требование | Версия |
|---|---|
| PHP | **8.4** или выше |
| Symfony | **7.0** или выше |
| doctrine/dbal | 4.0 (подтягивается автоматически) |
| База данных | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Шаг 1 — Установка через Composer

```bash
docker compose exec app composer require weaver/orm
```

Это установит:

- `weaver/orm` — основной маппер, построитель запросов и единицу работы (unit of work)
- `weaver/orm-bundle` — Symfony-бандл (регистрируется автоматически через Symfony Flex)
- `doctrine/dbal ^4.0` — используется как уровень подключения и абстракции схемы (не Doctrine ORM)

:::info Docker
Все команды в этой документации предполагают выполнение внутри Docker-контейнера. Замените имя сервиса (`app`) на соответствующее имя из вашего `docker-compose.yml`.
:::

## Шаг 2 — Регистрация бандла

Если вы используете Symfony Flex, бандл регистрируется автоматически. В противном случае добавьте его вручную в `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... другие бандлы ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Шаг 3 — Создание файла конфигурации

Создайте `config/packages/weaver.yaml` с минимальной конфигурацией подключения:

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

Добавьте URL базы данных в файл `.env` (или `.env.local` для локальных переопределений):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Шаг 4 — Проверка установки

```bash
docker compose exec app bin/console weaver:info
```

Ожидаемый вывод:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Поддерживаемые драйверы баз данных

| Драйвер | База данных |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (аналитический, оптимизированный для чтения) |

## Опциональные пакеты

### PyroSQL (аналитическая реплика для чтения)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Включает высокопроизводительный встроенный аналитический движок в качестве дополнительного подключения для тяжёлых запросов чтения и отчётности.

### Маппер документов MongoDB

```bash
docker compose exec app composer require mongodb/mongodb
```

Требует расширение PHP `ext-mongodb`. Включает `AbstractDocumentMapper` для документо-ориентированного хранилища рядом с реляционным маппером.

### Интеграция с Symfony Messenger

```bash
docker compose exec app composer require symfony/messenger
```

Включает паттерн Outbox и асинхронную публикацию доменных событий из хуков жизненного цикла сущностей.

### Кэширование результатов запросов

```bash
docker compose exec app composer require symfony/cache
```

Включает `->cache(ttl: 60)` в цепочках построителя запросов для хранения гидрированных результатов в PSR-6 пуле кэша.
