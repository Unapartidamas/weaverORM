---
id: relations
title: Relations
---

Weaver ORM defines all relation metadata inside the entity's **mapper class** via a `RelationMap`. There are no attributes on entity properties and no runtime reflection. Relations are always loaded **explicitly** — Weaver never issues surprise queries behind your back.

## Overview

### Owning vs inverse side

Every relation has one **owning side** and one **inverse side**.

- The **owning side** holds the foreign key in its table (or in the pivot table for many-to-many). It controls persistence of the association.
- The **inverse side** is declared with `mappedBy` pointing to the owning side. Changes made only to the inverse side are **not** written to the database.

### Foreign key placement rules

| Relation type | FK location | Mapper method |
|---|---|---|
| One-to-one | On the "other" entity's table | `hasOne` |
| One-to-many | On the "many" entity's table | `hasMany` |
| Many-to-one | On **this** entity's table | `belongsTo` |
| Many-to-many | Dedicated pivot table | `belongsToMany` |
| Polymorphic one-to-one | On the morphable entity's table | `morphOne` |
| Polymorphic one-to-many | On the morphable entity's table | `morphMany` |

### How relations are declared

Relations are registered inside the mapper's `relations(RelationMap $map)` method:

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

`HasOne` represents a one-to-one relationship where the foreign key lives on the **other** entity's table. A `User` has one `Profile`; the `profiles` table carries `user_id`.

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

Mapper (owning side is `ProfileMapper`; `UserMapper` holds the inverse):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // column on profiles table
        ->localKey('id')          // column on users table (PK)
        ->mappedBy('user');       // inverse property name on Profile
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

Eager loading:

```php
// One extra IN query — never N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Batch-load profiles for many users (single IN query)
$users = $repository->findAll(with: ['profile']);
```

Cascade persist:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Software engineer');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Inserts users row first, then profiles row with correct user_id
```

---

## HasMany

`HasMany` represents a one-to-many relationship where the foreign key lives on the **many** side. A `User` has many `Post`s; the `posts` table carries `user_id`.

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
        ->foreignKey('user_id')          // column on posts table
        ->localKey('id')                 // column on users table
        ->orderBy('created_at', 'DESC')  // default ordering
        ->orphanRemoval(true);           // delete posts removed from the collection
}
```

Working with the collection:

```php
// Eager load
$user = $repository->findById(1, with: ['posts']);

// Add
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Remove (with orphanRemoval: DELETE is issued automatically)
$user->posts->remove($postToDelete);
$em->flush();

// Filter in-memory
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Count without loading
$count = $repository->countRelation($user, 'posts');
```

Index collection by a field:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection keyed by post.id

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` represents a many-to-one relationship where the foreign key lives on **this** entity's table. A `Post` belongs to a `User`; the `posts` table carries `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // column on posts table (this entity)
        ->ownerKey('id');         // PK on users table
}
```

Optional (nullable) FK — guest comments without an owner:

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Eager loading:

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` represents a many-to-many relationship backed by a **pivot** (junction) table. A `Post` can have many `Tag`s; the `post_tag` table holds both foreign keys.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // junction table name
        ->foreignPivotKey('post_id')       // FK pointing to this entity
        ->relatedPivotKey('tag_id')        // FK pointing to related entity
        ->withPivot('role', 'joined_at')   // extra pivot columns to load
        ->withPivotTimestamps()            // adds created_at / updated_at on pivot
        ->orderByPivot('joined_at', 'ASC');
}
```

Accessing pivot data:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — role: ' . $pivot->get('role');
}
```

Managing the pivot table:

```php
// Attach one tag with pivot data
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Attach multiple
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Detach one
$em->relation($post, 'tags')->detach(tagId: 5);

// Detach all
$em->relation($post, 'tags')->detach();

// Sync: replace entire pivot set (detach removed, attach added)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Sync with pivot data
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Add only, never remove
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Toggle: attach if absent, detach if present
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Polymorphic relations allow a single relation to target more than one entity type. Two columns on the "morph" table identify the parent:

- `{name}_type` — stores the entity class (or a configured alias)
- `{name}_id` — stores the primary key

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK into whichever table
url
```

Mapper — owning side (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post has one cover image
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // resolves to imageable_type + imageable_id
        ->localKey('id');

    // Post has many images
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Mapper — morphable side (Image):

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // alias → class mapping
            'user' => User::class,
        ]);
}
```

Querying:

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` traverses two tables to resolve a single related entity. A `User` has one `Carrier` **through** their `Phone`.

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
        firstKey:   'user_id',      // FK on phones pointing to users
        secondKey:  'carrier_id',   // FK on phones pointing to carriers
        localKey:   'id',           // PK on users
        throughKey: 'id',           // PK on carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

Generated SQL uses a single `JOIN`:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` provides access to a distant collection via an intermediate entity. A `Country` has many `Post`s through its `User`s.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK on users pointing to countries
        secondKey:  'user_id',      // FK on posts pointing to users
        localKey:   'id',           // PK on countries
        throughKey: 'id',           // PK on users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// With constraint: only published posts
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Eager loading

### Basic eager loading

Pass relation names to the `with:` parameter of any repository method:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver uses **separate queries with `IN` clauses** — never `JOIN`s for collections — to avoid row multiplication.

### Dot-notation for nested relations

```php
// Load users → posts → comments → comment authors
// Exactly 4 queries total, regardless of the number of users
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Constrained eager loading

Pass a closure to filter or sort a relation at load time:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Nested constraints:

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

### Limit per parent entity

```php
// Load at most 3 posts per user (uses LATERAL JOIN on supported engines)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Relation aggregates (without loading)

Attach aggregate values to entities without fetching the full relation:

```php
// Add posts_count virtual property
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} has {$user->postsCount} posts";
}
```

```php
// Multiple aggregates in one call
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Constrained aggregate:

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

## Existence queries

```php
// Users with at least one post
$users = $userRepository->query()->has('posts')->get();

// Users with no posts
$users = $userRepository->query()->doesntHave('posts')->get();

// Users with more than 5 posts
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// Users with posts that have at least one published comment
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Cascade options

| Option | Effect |
|---|---|
| `CascadeType::Persist` | Persist related entities when the owning side is persisted |
| `CascadeType::Remove` | Delete related entities when the owning side is deleted |
| `->orphanRemoval(true)` | Delete HasMany members removed from the collection |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Cascades must be explicitly opted into. Weaver never cascades silently.
:::

---

## Self-referencing relations

Entities that reference their own table (categories, menus, org charts):

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

Recursive eager loading (bounded depth):

```php
// Load three levels deep: children → grandchildren → great-grandchildren
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Alternative dot-notation syntax
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
