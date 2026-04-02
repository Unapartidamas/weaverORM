---
id: relations
title: Relaciones
---

Weaver ORM define todos los metadatos de relaciones dentro de la **clase mapper** de la entidad mediante un `RelationMap`. No hay atributos en las propiedades de la entidad y no hay reflexión en tiempo de ejecución. Las relaciones siempre se cargan **explícitamente** — Weaver nunca emite consultas sorpresa por detrás.

## Descripción general

### Lado propietario vs lado inverso

Toda relación tiene un **lado propietario** y un **lado inverso**.

- El **lado propietario** tiene la clave foránea en su tabla (o en la tabla pivote para relaciones muchos-a-muchos). Controla la persistencia de la asociación.
- El **lado inverso** se declara con `mappedBy` apuntando al lado propietario. Los cambios realizados solo en el lado inverso **no** se escriben en la base de datos.

### Reglas de ubicación de clave foránea

| Tipo de relación | Ubicación de FK | Método del mapper |
|---|---|---|
| Uno-a-uno | En la tabla de la "otra" entidad | `hasOne` |
| Uno-a-muchos | En la tabla de la entidad "muchos" | `hasMany` |
| Muchos-a-uno | En la tabla de **esta** entidad | `belongsTo` |
| Muchos-a-muchos | Tabla pivote dedicada | `belongsToMany` |
| Polimórfico uno-a-uno | En la tabla de la entidad morfable | `morphOne` |
| Polimórfico uno-a-muchos | En la tabla de la entidad morfable | `morphMany` |

### Cómo se declaran las relaciones

Las relaciones se registran dentro del método `relations(RelationMap $map)` del mapper:

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

`HasOne` representa una relación uno-a-uno donde la clave foránea está en la tabla de la **otra** entidad. Un `User` tiene un `Profile`; la tabla `profiles` tiene `user_id`.

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

Mapper (el lado propietario es `ProfileMapper`; `UserMapper` tiene el inverso):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // columna en la tabla profiles
        ->localKey('id')          // columna en la tabla users (PK)
        ->mappedBy('user');       // nombre de la propiedad inversa en Profile
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

Carga anticipada:

```php
// Una consulta IN adicional — nunca N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Carga masiva de perfiles para muchos usuarios (una sola consulta IN)
$users = $repository->findAll(with: ['profile']);
```

Cascade persist:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Ingeniera de software');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Inserta primero la fila de users, luego la fila de profiles con el user_id correcto
```

---

## HasMany

`HasMany` representa una relación uno-a-muchos donde la clave foránea está en el lado **muchos**. Un `User` tiene muchos `Post`s; la tabla `posts` tiene `user_id`.

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
        ->foreignKey('user_id')          // columna en la tabla posts
        ->localKey('id')                 // columna en la tabla users
        ->orderBy('created_at', 'DESC')  // ordenamiento predeterminado
        ->orphanRemoval(true);           // elimina los posts removidos de la colección
}
```

Trabajando con la colección:

```php
// Carga anticipada
$user = $repository->findById(1, with: ['posts']);

// Añadir
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Eliminar (con orphanRemoval: se emite DELETE automáticamente)
$user->posts->remove($postToDelete);
$em->flush();

// Filtrar en memoria
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Contar sin cargar
$count = $repository->countRelation($user, 'posts');
```

Indexar colección por un campo:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection indexada por post.id

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` representa una relación muchos-a-uno donde la clave foránea está en la tabla de **esta** entidad. Un `Post` pertenece a un `User`; la tabla `posts` tiene `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // columna en la tabla posts (esta entidad)
        ->ownerKey('id');         // PK en la tabla users
}
```

FK opcional (nullable) — comentarios de invitados sin propietario:

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Carga anticipada:

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` representa una relación muchos-a-muchos respaldada por una tabla **pivote** (tabla de unión). Un `Post` puede tener muchos `Tag`s; la tabla `post_tag` contiene ambas claves foráneas.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // nombre de la tabla de unión
        ->foreignPivotKey('post_id')       // FK apuntando a esta entidad
        ->relatedPivotKey('tag_id')        // FK apuntando a la entidad relacionada
        ->withPivot('role', 'joined_at')   // columnas pivote adicionales a cargar
        ->withPivotTimestamps()            // añade created_at / updated_at en el pivote
        ->orderByPivot('joined_at', 'ASC');
}
```

Acceder a los datos del pivote:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — rol: ' . $pivot->get('role');
}
```

Administrar la tabla pivote:

```php
// Adjuntar un tag con datos del pivote
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Adjuntar múltiples
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Desadjuntar uno
$em->relation($post, 'tags')->detach(tagId: 5);

// Desadjuntar todos
$em->relation($post, 'tags')->detach();

// Sync: reemplaza el conjunto completo del pivote (desadjunta removidos, adjunta añadidos)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Sync con datos del pivote
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Solo añadir, nunca remover
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Toggle: adjuntar si está ausente, desadjuntar si está presente
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Las relaciones polimórficas permiten que una sola relación apunte a más de un tipo de entidad. Dos columnas en la tabla "morph" identifican al padre:

- `{name}_type` — almacena la clase de entidad (o un alias configurado)
- `{name}_id` — almacena la clave primaria

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK hacia la tabla correspondiente
url
```

Mapper — lado propietario (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post tiene una imagen de portada
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // resuelve a imageable_type + imageable_id
        ->localKey('id');

    // Post tiene muchas imágenes
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Mapper — lado morfable (Image):

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // mapeo alias → clase
            'user' => User::class,
        ]);
}
```

Consultas:

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` atraviesa dos tablas para resolver una sola entidad relacionada. Un `User` tiene un `Carrier` **a través** de su `Phone`.

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
        firstKey:   'user_id',      // FK en phones apuntando a users
        secondKey:  'carrier_id',   // FK en phones apuntando a carriers
        localKey:   'id',           // PK en users
        throughKey: 'id',           // PK en carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

El SQL generado usa un solo `JOIN`:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` proporciona acceso a una colección lejana a través de una entidad intermedia. Un `Country` tiene muchos `Post`s a través de sus `User`s.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK en users apuntando a countries
        secondKey:  'user_id',      // FK en posts apuntando a users
        localKey:   'id',           // PK en countries
        throughKey: 'id',           // PK en users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// Con restricción: solo posts publicados
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Carga anticipada (eager loading)

### Carga anticipada básica

Pasa nombres de relaciones al parámetro `with:` de cualquier método del repositorio:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver usa **consultas separadas con cláusulas `IN`** — nunca `JOIN`s para colecciones — para evitar la multiplicación de filas.

### Notación de punto para relaciones anidadas

```php
// Carga users → posts → comments → autores de comentarios
// Exactamente 4 consultas en total, sin importar el número de usuarios
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Carga anticipada con restricciones

Pasa un closure para filtrar u ordenar una relación en el momento de carga:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Restricciones anidadas:

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

### Límite por entidad padre

```php
// Carga como máximo 3 posts por usuario (usa LATERAL JOIN en motores compatibles)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Agregados de relaciones (sin cargar)

Adjunta valores agregados a las entidades sin obtener la relación completa:

```php
// Añadir propiedad virtual posts_count
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} tiene {$user->postsCount} posts";
}
```

```php
// Múltiples agregados en una sola llamada
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Agregado con restricción:

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

## Consultas de existencia

```php
// Usuarios con al menos un post
$users = $userRepository->query()->has('posts')->get();

// Usuarios sin posts
$users = $userRepository->query()->doesntHave('posts')->get();

// Usuarios con más de 5 posts
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// Usuarios con posts que tienen al menos un comentario publicado
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Opciones de cascade

| Opción | Efecto |
|---|---|
| `CascadeType::Persist` | Persiste las entidades relacionadas cuando se persiste el lado propietario |
| `CascadeType::Remove` | Elimina las entidades relacionadas cuando se elimina el lado propietario |
| `->orphanRemoval(true)` | Elimina los miembros de HasMany removidos de la colección |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Los cascades deben optarse explícitamente. Weaver nunca hace cascade en silencio.
:::

---

## Relaciones auto-referenciales

Entidades que referencian su propia tabla (categorías, menús, organigramas):

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

Carga anticipada recursiva (con profundidad limitada):

```php
// Carga tres niveles de profundidad: hijos → nietos → bisnietos
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Sintaxis alternativa con notación de punto
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
