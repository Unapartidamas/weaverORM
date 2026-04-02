---
id: configuration
title: Configuração do Symfony
---

O Weaver ORM é configurado via `config/packages/weaver.yaml`. Esta página cobre todas as opções disponíveis.

## Configuração mínima

```yaml
# config/packages/weaver.yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
```

## Referência completa de configuração

```yaml
# config/packages/weaver.yaml
weaver_orm:

    # ------------------------------------------------------------------ #
    # Conexão primária (escrita)
    # ------------------------------------------------------------------ #
    connection:
        driver: pdo_pgsql           # pdo_pgsql | pdo_mysql | pdo_sqlite | pyrosql
        url: '%env(DATABASE_URL)%'  # DSN tem prioridade sobre as opções individuais abaixo

        # Opções individuais (alternativa ao url:)
        # host:     '%env(DB_HOST)%'
        # port:     '%env(int:DB_PORT)%'
        # dbname:   '%env(DB_NAME)%'
        # user:     '%env(DB_USER)%'
        # password: '%env(DB_PASSWORD)%'

        # Opções de pool de conexão (FrankenPHP / RoadRunner)
        # persistent: true
        # charset:    utf8mb4      # Apenas MySQL

    # ------------------------------------------------------------------ #
    # Réplica de leitura (opcional)
    # ------------------------------------------------------------------ #
    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'

    # ------------------------------------------------------------------ #
    # Descoberta de mappers
    # ------------------------------------------------------------------ #
    mapper_paths:
        - '%kernel.project_dir%/src/Mapper'
        # Adicione mais caminhos para múltiplos contextos delimitados:
        # - '%kernel.project_dir%/src/Billing/Mapper'
        # - '%kernel.project_dir%/src/Catalog/Mapper'

    # ------------------------------------------------------------------ #
    # Migrações
    # ------------------------------------------------------------------ #
    migrations_path: '%kernel.project_dir%/migrations/weaver'
    migrations_namespace: 'App\Migrations\Weaver'

    # ------------------------------------------------------------------ #
    # Debug e segurança
    # ------------------------------------------------------------------ #
    debug: '%kernel.debug%'         # Registra todas as consultas no profiler do Symfony

    # Detecta e avisa sobre padrões de consulta N+1 no desenvolvimento
    n1_detector: true               # Ativo apenas quando debug: true

    # Lança uma exceção se um SELECT retornar mais de N linhas.
    # Protege contra varreduras acidentais de tabela completa em produção.
    # Defina como 0 para desativar.
    max_rows_safety_limit: 5000
```

## Drivers de conexão

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

### pdo_sqlite — SQLite (testes / embarcado)

```yaml
weaver_orm:
    connection:
        driver: pdo_sqlite
        url: '%env(DATABASE_URL)%'
```

```dotenv
# Em memória (útil para testes de integração):
DATABASE_URL="sqlite:///:memory:"

# Baseado em arquivo:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/app.db"
```

### pyrosql — Motor analítico PyroSQL

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    # PyroSQL como conexão de leitura dedicada para consultas analíticas
    read_connection:
        driver: pyrosql
        url: '%env(VALKARNSQL_URL)%'
```

## Variáveis de ambiente

Uma configuração típica de `.env` / `.env.local`:

```dotenv
# .env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# .env.local (nunca commitado no VCS)
DATABASE_URL="postgresql://app:mylocalpwd@db:5432/app_dev?serverVersion=16&charset=utf8"

# Réplica de leitura (opcional)
DATABASE_READ_URL="postgresql://app_ro:readpwd@db-replica:5432/app?serverVersion=16&charset=utf8"
```

## Configuração de réplica de leitura

Quando `read_connection` é definido, o Weaver automaticamente roteia consultas `SELECT` para a conexão de leitura e consultas `INSERT` / `UPDATE` / `DELETE` para a conexão primária.

```yaml
weaver_orm:
    connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_URL)%'

    read_connection:
        driver: pdo_pgsql
        url: '%env(DATABASE_READ_URL)%'
```

Para forçar uma consulta na conexão primária (por exemplo, imediatamente após uma escrita), use `->onPrimary()`:

```php
// Sempre lê da primária, ignorando a réplica
$user = $this->users->query()
    ->onPrimary()
    ->where('id', '=', $id)
    ->first();
```

## Modo debug e o detector de N+1

Quando `debug: true` (padrão do Symfony no ambiente `dev`), o Weaver:

- Registra cada consulta SQL com seus parâmetros no Symfony Web Profiler.
- Ativa o **detector de N+1** quando `n1_detector: true`. O detector inspeciona padrões de carregamento ansioso e emite um aviso no profiler se detectar que uma relação foi acessada em múltiplas entidades sem ser pré-carregada.

```yaml
# config/packages/dev/weaver.yaml
weaver_orm:
    debug: true
    n1_detector: true
    max_rows_safety_limit: 1000  # mais restritivo em dev
```

```yaml
# config/packages/prod/weaver.yaml
weaver_orm:
    debug: false
    n1_detector: false
    max_rows_safety_limit: 5000
```

## Múltiplos contextos delimitados

Se sua aplicação usa múltiplos bancos de dados ou você quer separação estrita entre contextos delimitados, pode registrar múltiplos conjuntos de conexão usando o sistema de tags de serviço do Symfony diretamente. Consulte o [guia de configuração avançada](#) para detalhes.
