---
id: relations
title: Relações
---

O Weaver ORM define todos os metadados de relação dentro da **classe mapper** da entidade via um `RelationMap`. Não há atributos nas propriedades da entidade e nenhuma reflexão em tempo de execução. As relações são sempre carregadas de forma **explícita** — o Weaver nunca emite consultas surpresa por baixo dos panos.

## Visão geral

### Lado proprietário vs lado inverso

Toda relação tem um **lado proprietário** e um **lado inverso**.

- O **lado proprietário** mantém a chave estrangeira em sua tabela (ou na tabela pivot para muitos-para-muitos). Ele controla a persistência da associação.
- O **lado inverso** é declarado com `mappedBy` apontando para o lado proprietário. Mudanças feitas apenas no lado inverso **não** são escritas no banco de dados.

### Regras de posicionamento da chave estrangeira

| Tipo de relação | Localização da FK | Método do mapper |
|---|---|---|
| Um-para-um | Na tabela da "outra" entidade | `hasOne` |
| Um-para-muitos | Na tabela da entidade "muitos" | `hasMany` |
| Muitos-para-um | Na tabela **desta** entidade | `belongsTo` |
| Muitos-para-muitos | Tabela pivot dedicada | `belongsToMany` |
| Um-para-um polimórfico | Na tabela da entidade morphable | `morphOne` |
| Um-para-muitos polimórfico | Na tabela da entidade morphable | `morphMany` |

### Como as relações são declaradas

As relações são registradas dentro do método `relations(RelationMap $map)` do mapper:

```php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')
        ->localKey('id');

    $map->hasMany('posts', Post::class)
        ->foreignKey('user_id')
        ->localKey('id')
        ->orderBy('created_at', 'DESC');
}
```

---

## HasOne

`HasOne` representa uma relação um-para-um onde a chave estrangeira fica na tabela da **outra** entidade. Um `User` tem um `Profile`; a tabela `profiles` contém `user_id`.

```php
// src/Entity/User.php
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public ?Profile $profile = null,
    ) {}
}
```

```php
// src/Entity/Profile.php
final class Profile
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $bio,
        public ?User $user = null,
    ) {}
}
```

Mapper (o lado proprietário é o `ProfileMapper`; o `UserMapper` mantém o lado inverso):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // coluna na tabela profiles
        ->localKey('id')          // coluna na tabela users (PK)
        ->mappedBy('user');       // nome da propriedade inversa no Profile
}
```

```php
// src/Mapper/ProfileMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('user', User::class)
        ->foreignKey('user_id')
        ->ownerKey('id');
}
```

Carregamento ansioso (eager loading):

```php
// Uma consulta IN extra — nunca N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Carregamento em lote de profiles para muitos usuários (única consulta IN)
$users = $repository->findAll(with: ['profile']);
```

Cascade persist:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Engenheira de software');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Insere primeiro a linha users, depois a linha profiles com o user_id correto
```

---

## HasMany

`HasMany` representa uma relação um-para-muitos onde a chave estrangeira fica no lado **muitos**. Um `User` tem muitos `Post`s; a tabela `posts` contém `user_id`.

```php
// src/Entity/User.php
use Weaver\ORM\Collection\EntityCollection;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        /** @var EntityCollection<Post> */
        public EntityCollection $posts = new EntityCollection(),
    ) {}
}
```

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasMany('posts', Post::class)
        ->foreignKey('user_id')          // coluna na tabela posts
        ->localKey('id')                 // coluna na tabela users
        ->orderBy('created_at', 'DESC')  // ordenação padrão
        ->orphanRemoval(true);           // exclui posts removidos da coleção
}
```

Trabalhando com a coleção:

```php
// Carregamento ansioso
$user = $repository->findById(1, with: ['posts']);

// Adicionar
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Remover (com orphanRemoval: DELETE é emitido automaticamente)
$user->posts->remove($postToDelete);
$em->flush();

// Filtrar na memória
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Contar sem carregar
$count = $repository->countRelation($user, 'posts');
```

Indexar coleção por um campo:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection indexada por post.id

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` representa uma relação muitos-para-um onde a chave estrangeira fica na tabela **desta** entidade. Um `Post` pertence a um `User`; a tabela `posts` contém `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // coluna na tabela posts (esta entidade)
        ->ownerKey('id');         // PK na tabela users
}
```

FK opcional (nullable) — comentários de visitantes sem proprietário:

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Carregamento ansioso:

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` representa uma relação muitos-para-muitos suportada por uma tabela **pivot** (junção). Um `Post` pode ter muitas `Tag`s; a tabela `post_tag` contém ambas as chaves estrangeiras.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // nome da tabela de junção
        ->foreignPivotKey('post_id')       // FK apontando para esta entidade
        ->relatedPivotKey('tag_id')        // FK apontando para a entidade relacionada
        ->withPivot('role', 'joined_at')   // colunas pivot extras para carregar
        ->withPivotTimestamps()            // adiciona created_at / updated_at no pivot
        ->orderByPivot('joined_at', 'ASC');
}
```

Acessando dados do pivot:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — papel: ' . $pivot->get('role');
}
```

Gerenciando a tabela pivot:

```php
// Anexar uma tag com dados pivot
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Anexar múltiplos
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Desanexar um
$em->relation($post, 'tags')->detach(tagId: 5);

// Desanexar todos
$em->relation($post, 'tags')->detach();

// Sincronizar: substituir o conjunto pivot inteiro (desanexa removidos, anexa adicionados)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Sincronizar com dados pivot
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Apenas adicionar, nunca remover
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Alternar: anexar se ausente, desanexar se presente
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Relações polimórficas permitem que uma única relação aponte para mais de um tipo de entidade. Duas colunas na tabela "morph" identificam o pai:

- `{name}_type` — armazena a classe da entidade (ou um alias configurado)
- `{name}_id` — armazena a chave primária

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK para qualquer tabela
url
```

Mapper — lado proprietário (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post tem uma imagem de capa
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // resolve para imageable_type + imageable_id
        ->localKey('id');

    // Post tem muitas imagens
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Mapper — lado morphable (Image):

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // mapeamento alias → classe
            'user' => User::class,
        ]);
}
```

Consultando:

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` percorre duas tabelas para resolver uma única entidade relacionada. Um `User` tem um `Carrier` **através** do seu `Phone`.

```
users       phones           carriers
──────      ──────────────   ──────────
id          id               id
name        user_id  (FK)    name
            carrier_id (FK)
```

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOneThrough(
        relation:   'carrier',
        related:    Carrier::class,
        through:    Phone::class,
        firstKey:   'user_id',      // FK em phones apontando para users
        secondKey:  'carrier_id',   // FK em phones apontando para carriers
        localKey:   'id',           // PK em users
        throughKey: 'id',           // PK em carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

O SQL gerado usa um único `JOIN`:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` fornece acesso a uma coleção distante via uma entidade intermediária. Um `Country` tem muitos `Post`s através dos seus `User`s.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK em users apontando para countries
        secondKey:  'user_id',      // FK em posts apontando para users
        localKey:   'id',           // PK em countries
        throughKey: 'id',           // PK em users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// Com restrição: apenas posts publicados
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Carregamento ansioso (Eager loading)

### Carregamento ansioso básico

Passe nomes de relação para o parâmetro `with:` de qualquer método do repositório:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

O Weaver usa **consultas separadas com cláusulas `IN`** — nunca `JOIN`s para coleções — para evitar multiplicação de linhas.

### Notação de ponto para relações aninhadas

```php
// Carrega users → posts → comments → autores dos comentários
// Exatamente 4 consultas no total, independentemente do número de usuários
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Carregamento ansioso com restrições

Passe um closure para filtrar ou ordenar uma relação no momento do carregamento:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Restrições aninhadas:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) => $q
        ->where('published', true)
        ->with([
            'comments' => fn(RelationQuery $cq) => $cq
                ->where('approved', true)
                ->orderBy('created_at', 'ASC')
                ->limit(5),
        ]),
]);
```

### Limite por entidade pai

```php
// Carrega no máximo 3 posts por usuário (usa LATERAL JOIN em motores suportados)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Agregados de relação (sem carregamento)

Anexe valores agregados às entidades sem buscar a relação completa:

```php
// Adiciona a propriedade virtual posts_count
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} tem {$user->postsCount} posts";
}
```

```php
// Múltiplos agregados em uma chamada
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Agregado com restrição:

```php
$users = $userRepository->findAll(
    withCount: [
        'publishedPosts' => fn($q) => $q->where('published', true),
        'draftPosts'     => fn($q) => $q->where('published', false),
    ],
);
echo $user->publishedPostsCount;
echo $user->draftPostsCount;
```

---

## Consultas de existência

```php
// Usuários com pelo menos um post
$users = $userRepository->query()->has('posts')->get();

// Usuários sem posts
$users = $userRepository->query()->doesntHave('posts')->get();

// Usuários com mais de 5 posts
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// Usuários com posts que têm pelo menos um comentário aprovado
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Opções de cascade

| Opção | Efeito |
|---|---|
| `CascadeType::Persist` | Persiste entidades relacionadas quando o lado proprietário é persistido |
| `CascadeType::Remove` | Exclui entidades relacionadas quando o lado proprietário é excluído |
| `->orphanRemoval(true)` | Exclui membros HasMany removidos da coleção |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Cascades devem ser explicitamente optados. O Weaver nunca faz cascade silenciosamente.
:::

---

## Relações auto-referenciadas

Entidades que referenciam sua própria tabela (categorias, menus, organogramas):

```php
// src/Mapper/CategoryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasMany('children', Category::class)
        ->foreignKey('parent_id')
        ->localKey('id')
        ->orderBy('name', 'ASC')
        ->orphanRemoval(true);

    $map->belongsTo('parent', Category::class)
        ->foreignKey('parent_id')
        ->ownerKey('id')
        ->nullable(true);
}
```

Carregamento ansioso recursivo (profundidade limitada):

```php
// Carrega três níveis de profundidade: filhos → netos → bisnetos
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Sintaxe alternativa com notação de ponto
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
