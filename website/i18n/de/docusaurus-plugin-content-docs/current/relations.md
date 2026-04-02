---
id: relations
title: Beziehungen
---

Weaver ORM definiert alle Beziehungs-Metadaten innerhalb der **Mapper-Klasse** der Entity über eine `RelationMap`. Es gibt keine Attribute auf Entity-Eigenschaften und keine Laufzeit-Reflection. Beziehungen werden immer **explizit** geladen — Weaver gibt niemals überraschende Abfragen hinter Ihrem Rücken aus.

## Überblick

### Owning-Seite vs. Inverse-Seite

Jede Beziehung hat eine **Owning-Seite** (besitzende Seite) und eine **Inverse-Seite** (umgekehrte Seite).

- Die **Owning-Seite** hält den Fremdschlüssel in ihrer Tabelle (oder in der Pivot-Tabelle für Many-to-Many). Sie steuert die Persistenz der Assoziation.
- Die **Inverse-Seite** wird mit `mappedBy` deklariert, das auf die Owning-Seite zeigt. Änderungen, die nur an der Inverse-Seite vorgenommen werden, werden **nicht** in die Datenbank geschrieben.

### Regeln zur Fremdschlüsselplatzierung

| Beziehungstyp | FK-Ort | Mapper-Methode |
|---|---|---|
| One-to-One | In der Tabelle der "anderen" Entity | `hasOne` |
| One-to-Many | In der Tabelle der "vielen" Entity | `hasMany` |
| Many-to-One | In der Tabelle **dieser** Entity | `belongsTo` |
| Many-to-Many | Dedizierte Pivot-Tabelle | `belongsToMany` |
| Polymorphes One-to-One | In der Tabelle der morphbaren Entity | `morphOne` |
| Polymorphes One-to-Many | In der Tabelle der morphbaren Entity | `morphMany` |

### Wie Beziehungen deklariert werden

Beziehungen werden innerhalb der `relations(RelationMap $map)`-Methode des Mappers registriert:

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

`HasOne` repräsentiert eine One-to-One-Beziehung, bei der der Fremdschlüssel in der Tabelle der **anderen** Entity liegt. Ein `User` hat ein `Profile`; die `profiles`-Tabelle enthält `user_id`.

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

Mapper (Owning-Seite ist `ProfileMapper`; `UserMapper` hält die Inverse):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // Spalte in der profiles-Tabelle
        ->localKey('id')          // Spalte in der users-Tabelle (PK)
        ->mappedBy('user');       // Name der inversen Eigenschaft in Profile
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

Eager Loading:

```php
// Eine zusätzliche IN-Abfrage — niemals N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Profile für viele User batch-laden (einzelne IN-Abfrage)
$users = $repository->findAll(with: ['profile']);
```

Cascade Persist:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Software engineer');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Fügt zuerst die users-Zeile ein, dann die profiles-Zeile mit korrekter user_id
```

---

## HasMany

`HasMany` repräsentiert eine One-to-Many-Beziehung, bei der der Fremdschlüssel auf der **Many**-Seite liegt. Ein `User` hat viele `Post`-Objekte; die `posts`-Tabelle enthält `user_id`.

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
        ->foreignKey('user_id')          // Spalte in der posts-Tabelle
        ->localKey('id')                 // Spalte in der users-Tabelle
        ->orderBy('created_at', 'DESC')  // Standardsortierung
        ->orphanRemoval(true);           // Posts löschen, die aus der Collection entfernt wurden
}
```

Mit der Collection arbeiten:

```php
// Eager Load
$user = $repository->findById(1, with: ['posts']);

// Hinzufügen
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Entfernen (mit orphanRemoval: DELETE wird automatisch ausgeführt)
$user->posts->remove($postToDelete);
$em->flush();

// Im Speicher filtern
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Ohne Laden zählen
$count = $repository->countRelation($user, 'posts');
```

Collection nach einem Feld indizieren:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection nach post.id indiziert

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` repräsentiert eine Many-to-One-Beziehung, bei der der Fremdschlüssel in der Tabelle **dieser** Entity liegt. Ein `Post` gehört zu einem `User`; die `posts`-Tabelle enthält `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // Spalte in der posts-Tabelle (diese Entity)
        ->ownerKey('id');         // PK in der users-Tabelle
}
```

Optionaler (nullable) FK — Gastkommentare ohne Besitzer:

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Eager Loading:

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` repräsentiert eine Many-to-Many-Beziehung, die durch eine **Pivot**-Tabelle (Verbindungstabelle) unterstützt wird. Ein `Post` kann viele `Tag`-Objekte haben; die `post_tag`-Tabelle enthält beide Fremdschlüssel.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // Name der Verbindungstabelle
        ->foreignPivotKey('post_id')       // FK, der auf diese Entity zeigt
        ->relatedPivotKey('tag_id')        // FK, der auf die verknüpfte Entity zeigt
        ->withPivot('role', 'joined_at')   // Zusätzliche Pivot-Spalten zum Laden
        ->withPivotTimestamps()            // Fügt created_at / updated_at auf der Pivot hinzu
        ->orderByPivot('joined_at', 'ASC');
}
```

Zugriff auf Pivot-Daten:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — Rolle: ' . $pivot->get('role');
}
```

Pivot-Tabelle verwalten:

```php
// Einen Tag mit Pivot-Daten anhängen
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Mehrere anhängen
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Einen ablösen
$em->relation($post, 'tags')->detach(tagId: 5);

// Alle ablösen
$em->relation($post, 'tags')->detach();

// Synchronisieren: gesamten Pivot-Satz ersetzen (entfernte ablösen, neue anhängen)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Mit Pivot-Daten synchronisieren
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Nur hinzufügen, nie entfernen
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Umschalten: anhängen wenn nicht vorhanden, ablösen wenn vorhanden
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Polymorphe Beziehungen ermöglichen es einer einzelnen Beziehung, mehr als einen Entity-Typ anzusprechen. Zwei Spalten in der "Morph"-Tabelle identifizieren das Elternelement:

- `{name}_type` — speichert die Entity-Klasse (oder einen konfigurierten Alias)
- `{name}_id` — speichert den Primärschlüssel

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK in die jeweilige Tabelle
url
```

Mapper — Owning-Seite (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post hat ein Cover-Bild
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // wird zu imageable_type + imageable_id aufgelöst
        ->localKey('id');

    // Post hat viele Bilder
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Mapper — Morphbare Seite (Image):

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // Alias → Klassen-Mapping
            'user' => User::class,
        ]);
}
```

Abfragen:

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` durchquert zwei Tabellen, um eine einzelne verknüpfte Entity aufzulösen. Ein `User` hat einen `Carrier` **durch** sein `Phone`.

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
        firstKey:   'user_id',      // FK auf phones, der auf users zeigt
        secondKey:  'carrier_id',   // FK auf phones, der auf carriers zeigt
        localKey:   'id',           // PK auf users
        throughKey: 'id',           // PK auf carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

Das generierte SQL verwendet einen einzelnen `JOIN`:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` ermöglicht den Zugriff auf eine entfernte Collection über eine Zwischenentity. Ein `Country` hat viele `Post`-Objekte über seine `User`-Objekte.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK auf users, der auf countries zeigt
        secondKey:  'user_id',      // FK auf posts, der auf users zeigt
        localKey:   'id',           // PK auf countries
        throughKey: 'id',           // PK auf users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// Mit Einschränkung: nur veröffentlichte Posts
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Eager Loading

### Einfaches Eager Loading

Beziehungsnamen an den `with:`-Parameter einer beliebigen Repository-Methode übergeben:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver verwendet **separate Abfragen mit `IN`-Klauseln** — niemals `JOIN`s für Collections — um Zeilenmultiplikation zu vermeiden.

### Punkt-Notation für verschachtelte Beziehungen

```php
// User → Posts → Kommentare → Kommentarautoren laden
// Genau 4 Abfragen insgesamt, unabhängig von der Anzahl der User
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Eingeschränktes Eager Loading

Eine Closure übergeben, um eine Beziehung beim Laden zu filtern oder zu sortieren:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Verschachtelte Einschränkungen:

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

### Limit pro Eltern-Entity

```php
// Maximal 3 Posts pro User laden (verwendet LATERAL JOIN bei unterstützten Engines)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Beziehungs-Aggregate (ohne Laden)

Aggregatwerte an Entities anhängen, ohne die vollständige Beziehung zu laden:

```php
// Virtuelle Eigenschaft posts_count hinzufügen
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} hat {$user->postsCount} Posts";
}
```

```php
// Mehrere Aggregate in einem Aufruf
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Eingeschränktes Aggregat:

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

## Existenzabfragen

```php
// User mit mindestens einem Post
$users = $userRepository->query()->has('posts')->get();

// User ohne Posts
$users = $userRepository->query()->doesntHave('posts')->get();

// User mit mehr als 5 Posts
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// User mit Posts, die mindestens einen veröffentlichten Kommentar haben
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Cascade-Optionen

| Option | Wirkung |
|---|---|
| `CascadeType::Persist` | Verknüpfte Entities persistieren, wenn die Owning-Seite persistiert wird |
| `CascadeType::Remove` | Verknüpfte Entities löschen, wenn die Owning-Seite gelöscht wird |
| `->orphanRemoval(true)` | HasMany-Mitglieder löschen, die aus der Collection entfernt wurden |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Cascades müssen explizit aktiviert werden. Weaver kaskadiert niemals stillschweigend.
:::

---

## Selbstreferenzierende Beziehungen

Entities, die auf ihre eigene Tabelle verweisen (Kategorien, Menüs, Organigramme):

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

Rekursives Eager Loading (begrenztetiefe):

```php
// Drei Ebenen tief laden: Kinder → Enkel → Urenkel
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Alternative Punkt-Notation-Syntax
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
