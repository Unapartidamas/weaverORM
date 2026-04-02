---
id: configuration
title: Конфигурация Symfony
---

Weaver ORM настраивается через `config/packages/weaver.yaml`. На этой странице описаны все доступные параметры.

## Минимальная конфигурация

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Полный справочник конфигурации

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Основное (запись) подключение
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN имеет приоритет над индивидуальными параметрами ниже

        # Индивидуальные параметры (альтернатива url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Параметры пула соединений (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # только для MySQL

    # ------------------------------------------------------------------ #
    # Реплика для чтения (необязательно)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Обнаружение маппераов
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Добавьте дополнительные пути для нескольких ограниченных контекстов:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Миграции
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Отладка и безопасность
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Записывает все запросы в Symfony Profiler

    # Обнаружение и предупреждение о паттернах N+1 в разработке
    n1_detector: true               # Активен только при debug: true

    # Выбрасывает исключение, если SELECT вернёт больше N строк.
    # Защита от случайного полного сканирования таблицы в продакшне.
    # Установите 0 для отключения.
    max_rows_safety_limit: 5000
```

## Драйверы подключения

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

### pdo_sqlite — SQLite (тестирование / встроенная)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# В памяти (полезно для интеграционных тестов):
DATABASE_URL="sqlite:///:memory:"

# Файловая:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — аналитический движок PyroSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL как выделенное подключение для чтения аналитических запросов
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## Переменные окружения

Типичная настройка `.env` / `.env.local`:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (не коммитится в VCS)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Реплика для чтения (необязательно)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Конфигурация реплики для чтения

Когда задано `read_connection`, Weaver автоматически направляет запросы `SELECT` на подключение для чтения, а запросы `INSERT` / `UPDATE` / `DELETE` — на основное подключение.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

Чтобы принудительно направить запрос на основное подключение (например, сразу после записи), используйте `->onPrimary()`:

```php
// Всегда читает с основного, минуя реплику
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Режим отладки и детектор N+1

Когда `debug: true` (по умолчанию в Symfony в окружении `dev`), Weaver:

- Записывает каждый SQL-запрос с его привязками в Symfony Web Profiler.
- Активирует **детектор N+1** при `n1_detector: true`. Детектор инспектирует паттерны жадной загрузки и выводит предупреждение в профилере, если обнаружит, что к связи нескольких сущностей обращались без предварительной загрузки.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # строже в режиме разработки
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Несколько ограниченных контекстов

Если ваше приложение использует несколько баз данных или вам нужно строгое разделение ограниченных контекстов, можно зарегистрировать несколько наборов подключений, используя теги сервисов Symfony напрямую. Подробнее см. в [руководстве по расширенной конфигурации](#).
