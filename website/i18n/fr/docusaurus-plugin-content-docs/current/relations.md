---
id: relations
title: Relations
---

Weaver ORM définit toutes les métadonnées de relation à l'intérieur de la **classe mapper** de l'entité via une `RelationMap`. Il n'y a pas d'attributs sur les propriétés d'entité et pas de réflexion à l'exécution. Les relations sont toujours chargées **explicitement** — Weaver n'émet jamais de requêtes surprises dans votre dos.

## Vue d'ensemble

### Côté propriétaire vs côté inverse

Chaque relation a un **côté propriétaire** et un **côté inverse**.

- Le **côté propriétaire** détient la clé étrangère dans sa table (ou dans la table pivot pour le many-to-many). Il contrôle la persistance de l'association.
- Le **côté inverse** est déclaré avec `mappedBy` pointant vers le côté propriétaire. Les modifications apportées uniquement au côté inverse **ne sont pas** écrites dans la base de données.

### Règles de placement des clés étrangères

| Type de relation | Emplacement FK | Méthode mapper |
|---|---|---|
| Un-à-un | Sur la table de l'« autre » entité | `hasOne` |
| Un-à-plusieurs | Sur la table de l'entité « plusieurs » | `hasMany` |
| Plusieurs-à-un | Sur la table de **cette** entité | `belongsTo` |
| Plusieurs-à-plusieurs | Table pivot dédiée | `belongsToMany` |
| Un-à-un polymorphique | Sur la table de l'entité morphable | `morphOne` |
| Un-à-plusieurs polymorphique | Sur la table de l'entité morphable | `morphMany` |

### Comment les relations sont déclarées

Les relations sont enregistrées dans la méthode `relations(RelationMap $map)` du mapper :

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

`HasOne` représente une relation un-à-un où la clé étrangère se trouve sur la table de l'**autre** entité. Un `User` a un `Profile` ; la table `profiles` porte `user_id`.

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

Mapper (le côté propriétaire est `ProfileMapper` ; `UserMapper` détient l'inverse) :

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // colonne sur la table profiles
        ->localKey('id')          // colonne sur la table users (PK)
        ->mappedBy('user');       // nom de propriété inverse sur Profile
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

Chargement anticipé :

```php
// Une requête IN supplémentaire — jamais N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Chargement par lot des profils pour plusieurs utilisateurs (requête IN unique)
$users = $repository->findAll(with: ['profile']);
```

Cascade persist :

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Ingénieure logicielle');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Insère d'abord la ligne users, puis la ligne profiles avec le user_id correct
```

---

## HasMany

`HasMany` représente une relation un-à-plusieurs où la clé étrangère se trouve du côté **plusieurs**. Un `User` a plusieurs `Post`s ; la table `posts` porte `user_id`.

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
        ->foreignKey('user_id')          // colonne sur la table posts
        ->localKey('id')                 // colonne sur la table users
        ->orderBy('created_at', 'DESC')  // ordre par défaut
        ->orphanRemoval(true);           // supprime les posts retirés de la collection
}
```

Travailler avec la collection :

```php
// Chargement anticipé
$user = $repository->findById(1, with: ['posts']);

// Ajouter
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Supprimer (avec orphanRemoval : DELETE émis automatiquement)
$user->posts->remove($postToDelete);
$em->flush();

// Filtrer en mémoire
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Compter sans charger
$count = $repository->countRelation($user, 'posts');
```

Indexer la collection par un champ :

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection indexée par post.id

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` représente une relation plusieurs-à-un où la clé étrangère se trouve sur la table de **cette** entité. Un `Post` appartient à un `User` ; la table `posts` porte `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // colonne sur la table posts (cette entité)
        ->ownerKey('id');         // PK sur la table users
}
```

FK optionnelle (nullable) — commentaires d'invités sans propriétaire :

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Chargement anticipé :

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` représente une relation plusieurs-à-plusieurs soutenue par une table **pivot** (de jonction). Un `Post` peut avoir plusieurs `Tag`s ; la table `post_tag` détient les deux clés étrangères.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // nom de la table de jonction
        ->foreignPivotKey('post_id')       // FK pointant vers cette entité
        ->relatedPivotKey('tag_id')        // FK pointant vers l'entité liée
        ->withPivot('role', 'joined_at')   // colonnes pivot supplémentaires à charger
        ->withPivotTimestamps()            // ajoute created_at / updated_at sur le pivot
        ->orderByPivot('joined_at', 'ASC');
}
```

Accéder aux données pivot :

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — rôle : ' . $pivot->get('role');
}
```

Gérer la table pivot :

```php
// Attacher un tag avec des données pivot
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Attacher plusieurs
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Détacher un
$em->relation($post, 'tags')->detach(tagId: 5);

// Détacher tous
$em->relation($post, 'tags')->detach();

// Synchroniser : remplacer l'ensemble pivot entier (détacher les supprimés, attacher les ajoutés)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Synchroniser avec des données pivot
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Ajouter uniquement, ne jamais supprimer
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Basculer : attacher si absent, détacher si présent
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Les relations polymorphiques permettent à une seule relation de cibler plus d'un type d'entité. Deux colonnes sur la table « morph » identifient le parent :

- `{name}_type` — stocke la classe de l'entité (ou un alias configuré)
- `{name}_id` — stocke la clé primaire

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK vers quelle que soit la table
url
```

Mapper — côté propriétaire (Post) :

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post a une image de couverture
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // résout vers imageable_type + imageable_id
        ->localKey('id');

    // Post a plusieurs images
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Mapper — côté morphable (Image) :

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // correspondance alias → classe
            'user' => User::class,
        ]);
}
```

Requêtes :

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` traverse deux tables pour résoudre une seule entité liée. Un `User` a un `Carrier` **à travers** son `Phone`.

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
        firstKey:   'user_id',      // FK sur phones pointant vers users
        secondKey:  'carrier_id',   // FK sur phones pointant vers carriers
        localKey:   'id',           // PK sur users
        throughKey: 'id',           // PK sur carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

Le SQL généré utilise un seul `JOIN` :

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` donne accès à une collection distante via une entité intermédiaire. Un `Country` a plusieurs `Post`s à travers ses `User`s.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK sur users pointant vers countries
        secondKey:  'user_id',      // FK sur posts pointant vers users
        localKey:   'id',           // PK sur countries
        throughKey: 'id',           // PK sur users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// Avec contrainte : seulement les posts publiés
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Chargement anticipé

### Chargement anticipé de base

Passez les noms de relations au paramètre `with:` de toute méthode de repository :

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver utilise des **requêtes séparées avec des clauses `IN`** — jamais des `JOIN`s pour les collections — pour éviter la multiplication des lignes.

### Notation pointée pour les relations imbriquées

```php
// Charger utilisateurs → posts → commentaires → auteurs des commentaires
// Exactement 4 requêtes au total, quel que soit le nombre d'utilisateurs
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Chargement anticipé contraint

Passez une closure pour filtrer ou trier une relation au moment du chargement :

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Contraintes imbriquées :

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

### Limite par entité parente

```php
// Charger au plus 3 posts par utilisateur (utilise LATERAL JOIN sur les moteurs supportés)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Agrégats de relations (sans chargement)

Attacher des valeurs agrégées aux entités sans récupérer la relation complète :

```php
// Ajouter la propriété virtuelle posts_count
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} a {$user->postsCount} posts";
}
```

```php
// Plusieurs agrégats en un seul appel
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Agrégat contraint :

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

## Requêtes d'existence

```php
// Utilisateurs avec au moins un post
$users = $userRepository->query()->has('posts')->get();

// Utilisateurs sans post
$users = $userRepository->query()->doesntHave('posts')->get();

// Utilisateurs avec plus de 5 posts
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// Utilisateurs avec des posts ayant au moins un commentaire publié
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Options de cascade

| Option | Effet |
|---|---|
| `CascadeType::Persist` | Persister les entités liées quand le côté propriétaire est persisté |
| `CascadeType::Remove` | Supprimer les entités liées quand le côté propriétaire est supprimé |
| `->orphanRemoval(true)` | Supprimer les membres HasMany retirés de la collection |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Les cascades doivent être explicitement activées. Weaver ne cascade jamais silencieusement.
:::

---

## Relations auto-référencées

Entités qui référencent leur propre table (catégories, menus, organigrammes) :

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

Chargement anticipé récursif (profondeur bornée) :

```php
// Charger trois niveaux de profondeur : enfants → petits-enfants → arrière-petits-enfants
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Syntaxe alternative avec notation pointée
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
