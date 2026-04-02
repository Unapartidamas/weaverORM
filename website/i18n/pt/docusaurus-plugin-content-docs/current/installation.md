---
id: installation
title: Instalação
---

## Requisitos

Antes de instalar o Weaver ORM, verifique se o seu ambiente atende aos requisitos mínimos:

| Requisito | Versão |
|---|---|
| PHP | **8.4** ou superior |
| Symfony | **7.0** ou superior |
| doctrine/dbal | 4.0 (incluído automaticamente) |
| Banco de dados | MySQL 8.0+ / PostgreSQL 14+ / SQLite 3.35+ |

## Passo 1 — Instalar via Composer

```bash
docker compose exec app composer require weaver/orm
```

Isso instala:

- `weaver/orm` — o mapper central, o query builder e a unidade de trabalho
- `weaver/orm-bundle` — o bundle Symfony (registrado automaticamente pelo Symfony Flex)
- `doctrine/dbal ^4.0` — usado como camada de conexão e abstração de schema (não o Doctrine ORM)

:::info Docker
Todos os comandos nesta documentação assumem que você está executando dentro de um contêiner Docker. Ajuste o nome do serviço (`app`) para corresponder ao seu `docker-compose.yml`.
:::

## Passo 2 — Registrar o bundle

Se você usa o Symfony Flex, o bundle é registrado automaticamente. Caso contrário, adicione-o manualmente ao `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... outros bundles ...
    Weaver\ORM\Bundle\WeaverOrmBundle::class => ['all' => true],
];
```

## Passo 3 — Criar o arquivo de configuração

Crie o arquivo `config/packages/weaver.yaml` com uma configuração mínima de conexão:

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

Adicione a URL do banco de dados ao seu arquivo `.env` (ou `.env.local` para substituições locais):

```dotenv
DATABASE_URL="postgresql://app:secret@db:5432/app?serverVersion=16&charset=utf8"
```

## Passo 4 — Verificar a instalação

```bash
docker compose exec app bin/console weaver:info
```

Saída esperada:

```
Weaver ORM — version 1.0.0
Connection:   pdo_pgsql (connected)
Mapper paths: src/Mapper (0 mappers found)
Migrations:   migrations/weaver (0 migrations)
```

## Drivers de banco de dados suportados

| Driver | Banco de dados |
|---|---|
| `pdo_pgsql` | PostgreSQL 14+ |
| `pdo_mysql` | MySQL 8.0+ / MariaDB 10.6+ |
| `pdo_sqlite` | SQLite 3.35+ |
| `pyrosql` | PyroSQL (analítico, otimizado para leitura) |

## Pacotes opcionais

### PyroSQL (réplica de leitura analítica)

```bash
docker compose exec app composer require weaver/pyrosql-adapter
```

Habilita um mecanismo analítico em processo de alto desempenho como conexão secundária para consultas e relatórios intensivos em leitura.

### Mapeador de documentos MongoDB

```bash
docker compose exec app composer require mongodb/mongodb
```

Requer a extensão PHP `ext-mongodb`. Habilita o `AbstractDocumentMapper` para armazenamento orientado a documentos ao lado do mapper relacional.

### Integração com o Symfony Messenger

```bash
docker compose exec app composer require symfony/messenger
```

Habilita o padrão outbox e a publicação assíncrona de eventos de domínio a partir de hooks do ciclo de vida das entidades.

### Cache de resultados de consultas

```bash
docker compose exec app composer require symfony/cache
```

Habilita `->cache(ttl: 60)` em cadeias do query builder para armazenar resultados hidratados em um pool de cache PSR-6.
