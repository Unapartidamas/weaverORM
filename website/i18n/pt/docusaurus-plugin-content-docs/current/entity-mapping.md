---
id: entity-mapping
title: Mapeamento de Entidades
---

O Weaver ORM separa os objetos de domínio dos metadados de persistência colocando todas as informações de mapeamento em uma **classe mapper** dedicada. Esta página cobre todos os aspectos da configuração do mapper.

## Por que mappers em vez de atributos?

O Doctrine ORM coloca os metadados de mapeamento diretamente na classe de entidade via atributos do PHP 8:

```php
// Abordagem do Doctrine — a entidade conhece o banco de dados
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;
}
```

O Weaver mantém uma separação estrita:

```
Classe de entidade  →  objeto PHP simples, sem dependências do ORM
Classe mapper       →  todo o conhecimento de persistência vive aqui
```

Benefícios:
- **Zero reflexão em tempo de execução.** O mapper é PHP puro retornando arrays e escalares.
- **Sem classes proxy.** Nenhuma geração de código em disco é necessária.
- **Seguro para workers.** Os mappers não mantêm estado por requisição.
- **Testável em isolamento.** Instancie e inspecione um mapper em um teste unitário sem inicializar o Symfony.
- **Totalmente pesquisável.** Cada nome de coluna, cada tipo, cada opção aparece em texto simples e é visível no `git diff`.

## Mapper vs entidade: responsabilidades

| Preocupação | Reside em |
|---|---|
| Lógica de negócio, invariantes | Classe de entidade |
| Propriedades e tipos PHP | Classe de entidade |
| Nome da tabela e schema | Mapper |
| Nomes de colunas, tipos, opções | Mapper |
| Índices e restrições | Mapper |
| Hidratação (linha → entidade) | Mapper |
| Extração (entidade → linha) | Mapper |
| Relações | Mapper |

## Definição básica de entidade

Uma entidade é qualquer classe PHP. Ela não estende nada, não implementa nada e não importa nada do `Weaver\ORM`.

```php
<?php
// src/Entity/User.php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isActive,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public function withEmail(string $email): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            email:     $email,
            isActive:  $this->isActive,
            createdAt: $this->createdAt,
        );
    }
}
```

A entidade pode ser:
- **Imutável** (recomendado) — métodos de mutação retornam novas instâncias
- **Mutável** — propriedades públicas ou setters são aceitos
- **Abstrata** — para hierarquias de herança

## AbstractMapper

Cada entidade precisa de exatamente um mapper. Crie uma classe estendendo `Weaver\ORM\Mapping\AbstractMapper` e implemente os métodos obrigatórios.

```php
<?php
// src/Mapper/UserMapper.php

declare(strict_types=1);

namespace App\Mapper;

use App\Entity\User;
use DateTimeImmutable;
use Weaver\ORM\Mapping\AbstractMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\SchemaDefinition;

final class UserMapper extends AbstractMapper
{
    public function table(): string
    {
        return 'users';
    }

    public function primaryKey(): string|array
    {
        return 'id';
    }

    public function schema(): SchemaDefinition
    {
        return SchemaDefinition::define(
            ColumnDefinition::integer('id')->autoIncrement()->unsigned(),
            ColumnDefinition::string('name', 120)->notNull(),
            ColumnDefinition::string('email', 254)->unique()->notNull(),
            ColumnDefinition::boolean('is_active')->notNull()->default(true),
            ColumnDefinition::datetime('created_at')->notNull(),
        );
    }

    public function hydrate(array $row): User
    {
        return new User(
            id:        (int) $row['id'],
            name:      $row['name'],
            email:     $row['email'],
            isActive:  (bool) $row['is_active'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    public function dehydrate(object $entity): array
    {
        /** @var User $entity */
        $data = [
            'name'       => $entity->name,
            'email'      => $entity->email,
            'is_active'  => $entity->isActive,
            'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
        ];

        if ($entity->id !== null) {
            $data['id'] = $entity->id;
        }

        return $data;
    }
}
```

### Métodos obrigatórios do mapper

| Método | Finalidade |
|---|---|
| `table(): string` | Nome da tabela no banco de dados |
| `primaryKey(): string\|array` | Nome(s) da(s) coluna(s) para a chave primária |
| `schema(): SchemaDefinition` | Todas as definições de colunas para DDL e migrações |
| `hydrate(array $row): object` | Constrói uma entidade a partir de uma linha bruta do banco de dados |
| `dehydrate(object $entity): array` | Serializa uma entidade para um array coluna => valor |

### Métodos opcionais do mapper

| Método | Finalidade |
|---|---|
| `readOnly(): bool` | Retorne `true` para entidades suportadas por views (sem INSERT/UPDATE/DELETE) |
| `discriminatorColumn(): ?string` | Usado para Herança de Tabela Única (Single Table Inheritance) |
| `discriminatorMap(): array` | Usado para Herança de Tabela Única |
| `parentMapper(): ?string` | Usado para Herança de Tabela por Classe (Class Table Inheritance) |

## Tipos de coluna

Todas as definições de colunas usam métodos de fábrica estáticos no `ColumnDefinition`. Cada método retorna uma instância de `ColumnDefinition` com uma API de configuração fluente.

### string

Mapeia para `VARCHAR(n)`. O comprimento padrão é 255.

```php
ColumnDefinition::string('username')                    // VARCHAR(255) NOT NULL
ColumnDefinition::string('slug', 100)                   // VARCHAR(100) NOT NULL
ColumnDefinition::string('nickname')->nullable()        // VARCHAR(255) NULL
```

### integer, bigint, smallint

```php
ColumnDefinition::integer('sort_order')                 // INT NOT NULL
ColumnDefinition::integer('quantity')->default(0)       // INT NOT NULL DEFAULT 0
ColumnDefinition::integer('stock')->unsigned()          // INT UNSIGNED NOT NULL
ColumnDefinition::bigint('view_count')->default(0)      // BIGINT NOT NULL DEFAULT 0
ColumnDefinition::smallint('priority')->unsigned()      // SMALLINT UNSIGNED NOT NULL
```

### float e decimal

Use `decimal` para valores financeiros; `float` para coordenadas e medidas.

```php
ColumnDefinition::float('latitude')
ColumnDefinition::float('longitude')
ColumnDefinition::decimal('price', 10, 2)              // DECIMAL(10,2) NOT NULL
ColumnDefinition::decimal('tax_rate', 5, 4)->default('0.0000')
```

Hidrate `decimal` como string para preservar a precisão:

```php
price: $row['price'],  // manter como string, passar para um objeto de valor Money
```

### boolean

Mapeia para `TINYINT(1)` no MySQL, `BOOLEAN` no PostgreSQL/SQLite.

```php
ColumnDefinition::boolean('is_active')->default(true)
ColumnDefinition::boolean('email_verified')->default(false)
```

Sempre faça cast explicitamente no `hydrate`:

```php
isActive: (bool) $row['is_active'],
```

### datetime, date, time

```php
ColumnDefinition::datetime('published_at')->nullable()   // DATETIME NULL
ColumnDefinition::date('birth_date')->nullable()         // DATE NULL
ColumnDefinition::time('opens_at')                       // TIME NOT NULL
```

`datetime` retorna um `\DateTime` mutável. Prefira `datetimeImmutable` para código novo:

```php
ColumnDefinition::datetimeImmutable('created_at')        // DATETIME NOT NULL
ColumnDefinition::datetimeImmutable('updated_at')->nullable()
```

Hidratação:

```php
createdAt: new \DateTimeImmutable($row['created_at']),
updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable($row['updated_at']) : null,
```

Extração:

```php
'created_at' => $entity->createdAt->format('Y-m-d H:i:s'),
'updated_at' => $entity->updatedAt?->format('Y-m-d H:i:s'),
```

### json

Mapeia para `JSON` (MySQL 5.7.8+, PostgreSQL, SQLite). Você controla a codificação/decodificação no `hydrate` / `dehydrate`.

```php
ColumnDefinition::json('metadata')->nullable()
ColumnDefinition::json('settings')
```

Hidratação:

```php
metadata: $row['metadata'] !== null
    ? json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)
    : null,
```

Extração:

```php
'metadata' => $entity->metadata !== null
    ? json_encode($entity->metadata, JSON_THROW_ON_ERROR)
    : null,
```

### text, blob

```php
ColumnDefinition::text('body')                           // TEXT NOT NULL
ColumnDefinition::text('description')->nullable()        // TEXT NULL
ColumnDefinition::blob('thumbnail')                      // BLOB NOT NULL
```

### guid (UUID como CHAR(36))

```php
ColumnDefinition::guid('external_ref')->nullable()       // CHAR(36) NULL
```

## Tipos de chave primária

### Inteiro com auto-incremento

```php
ColumnDefinition::integer('id')->autoIncrement()->unsigned()
```

```sql
id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id)
```

O Weaver omite `id` do `INSERT` quando o valor é `null` e lê o valor gerado automaticamente.

### UUID v4 (aleatório)

```php
ColumnDefinition::guid('id')->primaryKey()
```

Gere o UUID no método de fábrica da entidade antes de persistir:

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v4(), name: $name);
}
```

### UUID v7 (ordenado por tempo, recomendado)

O UUID v7 inclui um prefixo de timestamp em milissegundos, tornando as chaves monotonicamente crescentes e reduzindo drasticamente as divisões de página B-tree em comparação com UUIDs aleatórios.

```php
ColumnDefinition::guid('id')->primaryKey()
```

```php
use Symfony\Component\Uid\Uuid;

public static function create(string $name): self
{
    return new self(id: (string) Uuid::v7(), name: $name);
}
```

### Chave de string natural

Quando a chave de negócio é naturalmente única (código de país, código de moeda, slug):

```php
ColumnDefinition::string('code', 3)->primaryKey()
```

### Chave primária composta

```php
ColumnDefinition::integer('user_id')->primaryKey(),
ColumnDefinition::integer('role_id')->primaryKey(),
ColumnDefinition::datetimeImmutable('assigned_at'),
```

```sql
PRIMARY KEY (user_id, role_id)
```

## Opções de coluna

Todas as opções estão disponíveis como métodos fluentes em `ColumnDefinition`:

| Método | Efeito |
|---|---|
| `->nullable()` | Coluna aceita valores NULL |
| `->default($value)` | Define uma cláusula DEFAULT no DDL |
| `->unsigned()` | Aplica UNSIGNED (apenas tipos inteiro) |
| `->unique()` | Adiciona uma restrição UNIQUE |
| `->primaryKey()` | Marca a coluna como parte da chave primária |
| `->autoIncrement()` | Adiciona AUTO_INCREMENT (apenas PKs inteiras) |
| `->generated()` | Coluna é computada pelo banco; excluída de INSERT/UPDATE |
| `->comment(string)` | Adiciona um comentário DDL no nível da coluna |

## Mapeamento de enums do PHP 8.1

Enums PHP com backing type (`string` ou `int`) mapeiam naturalmente para colunas do banco de dados.

### Enum com backing string

```php
enum OrderStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Shipped   = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
```

Mapper:

```php
ColumnDefinition::string('status', 20)
    ->comment('pending|confirmed|shipped|delivered|cancelled')
```

Hidratação:

```php
status: OrderStatus::from($row['status']),
```

Extração:

```php
'status' => $entity->status->value,
```

### Enum com backing int

```php
enum Priority: int
{
    case Low    = 1;
    case Normal = 2;
    case High   = 3;
    case Urgent = 4;
}
```

Mapper:

```php
ColumnDefinition::smallint('priority')->unsigned()
```

Hidratação:

```php
priority: Priority::from((int) $row['priority']),
```

### Enum nullable

```php
ColumnDefinition::string('resolution', 20)->nullable()
```

Hidratação:

```php
resolution: $row['resolution'] !== null
    ? Resolution::from($row['resolution'])
    : null,
```

:::tip
Sempre armazene `->value` (por exemplo, `'pending'`), nunca `->name` (por exemplo, `'Pending'`). Os rótulos podem ser renomeados livremente em PHP; os valores não podem ser alterados sem uma migração.
:::

## Colunas geradas / computadas

Colunas preenchidas pelo motor do banco de dados (por exemplo, `GENERATED ALWAYS AS`) devem ser excluídas das declarações `INSERT` e `UPDATE`.

```php
ColumnDefinition::string('full_name', 162)->generated(),
ColumnDefinition::decimal('total', 10, 2)->generated(),
```

O Weaver remove automaticamente as colunas `generated` das cargas de escrita. Elas ainda aparecem no `hydrate`.

## Aliases de coluna

Use um alias quando o nome da propriedade PHP for diferente do nome da coluna no banco de dados:

```php
// Propriedade PHP 'email' mapeia para a coluna DB 'usr_email'
ColumnDefinition::string('email')->alias('usr_email')
```

No `hydrate`, use o nome da coluna (o alias) como chave do array:

```php
email: $row['usr_email'],
```

No `dehydrate`, retorne o nome da coluna como chave:

```php
'usr_email' => $entity->email,
```

## Registrando mappers no Symfony

Se `autoconfigure: true` estiver definido em `config/services.yaml` (padrão do Symfony), qualquer classe que estenda `AbstractMapper` nos `mapper_paths` configurados é automaticamente marcada e registrada — sem necessidade de definição manual de serviço.

Para registro explícito ou para substituir padrões:

```yaml
# config/services.yaml
services:
    App\Mapper\UserMapper:
        tags:
            - { name: weaver.mapper }
```
