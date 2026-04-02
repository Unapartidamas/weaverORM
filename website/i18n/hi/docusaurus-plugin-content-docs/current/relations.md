---
id: relations
title: रिलेशन्स
---

Weaver ORM एक `RelationMap` के माध्यम से एंटिटी के **मैपर क्लास** के अंदर सभी रिलेशन मेटाडेटा परिभाषित करता है। एंटिटी प्रॉपर्टीज़ पर कोई एट्रिब्यूट नहीं हैं और कोई रनटाइम रिफ्लेक्शन नहीं है। रिलेशन्स हमेशा **स्पष्ट रूप से** लोड किए जाते हैं — Weaver आपकी पीठ के पीछे कभी surprise क्वेरीज़ नहीं जारी करता।

## अवलोकन

### Owning बनाम inverse side

हर रिलेशन में एक **owning side** और एक **inverse side** होता है।

- **Owning side** अपनी टेबल (या many-to-many के लिए pivot टेबल) में foreign key रखता है। यह association की persistence नियंत्रित करता है।
- **Inverse side** owning side की ओर इशारा करते हुए `mappedBy` के साथ घोषित किया जाता है। केवल inverse side पर किए गए बदलाव डेटाबेस में **नहीं** लिखे जाते।

### Foreign key placement नियम

| रिलेशन टाइप | FK स्थान | मैपर मेथड |
|---|---|---|
| One-to-one | "दूसरी" एंटिटी की टेबल पर | `hasOne` |
| One-to-many | "many" एंटिटी की टेबल पर | `hasMany` |
| Many-to-one | **इस** एंटिटी की टेबल पर | `belongsTo` |
| Many-to-many | समर्पित pivot टेबल | `belongsToMany` |
| Polymorphic one-to-one | morphable एंटिटी की टेबल पर | `morphOne` |
| Polymorphic one-to-many | morphable एंटिटी की टेबल पर | `morphMany` |

### रिलेशन्स कैसे घोषित किए जाते हैं

रिलेशन्स मैपर की `relations(RelationMap $map)` मेथड के अंदर पंजीकृत होते हैं:

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

`HasOne` एक one-to-one रिलेशनशिप दर्शाता है जहाँ foreign key **दूसरी** एंटिटी की टेबल पर रहती है। एक `User` का एक `Profile` होता है; `profiles` टेबल में `user_id` होता है।

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

मैपर (owning side `ProfileMapper` है; `UserMapper` inverse रखता है):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // profiles टेबल पर कॉलम
        ->localKey('id')          // users टेबल पर कॉलम (PK)
        ->mappedBy('user');       // Profile पर inverse property नाम
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
// एक अतिरिक्त IN क्वेरी — कभी N+1 नहीं
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// कई users के लिए profiles batch-load करें (एकल IN क्वेरी)
$users = $repository->findAll(with: ['profile']);
```

Cascade persist:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Software engineer');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// पहले users row insert करता है, फिर सही user_id के साथ profiles row
```

---

## HasMany

`HasMany` एक one-to-many रिलेशनशिप दर्शाता है जहाँ foreign key **many** side पर रहती है। एक `User` के कई `Post`s होते हैं; `posts` टेबल में `user_id` होता है।

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
        ->foreignKey('user_id')          // posts टेबल पर कॉलम
        ->localKey('id')                 // users टेबल पर कॉलम
        ->orderBy('created_at', 'DESC')  // डिफ़ॉल्ट ordering
        ->orphanRemoval(true);           // collection से हटाए गए posts को delete करें
}
```

collection के साथ काम करना:

```php
// Eager load
$user = $repository->findById(1, with: ['posts']);

// जोड़ें
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// हटाएं (orphanRemoval के साथ: DELETE स्वचालित रूप से जारी होता है)
$user->posts->remove($postToDelete);
$em->flush();

// in-memory filter
$published = $user->posts->filter(fn(Post $p) => $p->published);

// लोड किए बिना count करें
$count = $repository->countRelation($user, 'posts');
```

किसी field द्वारा collection को index करें:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // post.id द्वारा keyed EntityCollection

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` एक many-to-one रिलेशनशिप दर्शाता है जहाँ foreign key **इस** एंटिटी की टेबल पर रहती है। एक `Post` एक `User` का होता है; `posts` टेबल में `user_id` होता है।

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // posts टेबल पर कॉलम (यह एंटिटी)
        ->ownerKey('id');         // users टेबल पर PK
}
```

वैकल्पिक (nullable) FK — बिना owner के guest comments:

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

`BelongsToMany` एक many-to-many रिलेशनशिप दर्शाता है जो एक **pivot** (junction) टेबल द्वारा समर्थित है। एक `Post` के कई `Tag`s हो सकते हैं; `post_tag` टेबल दोनों foreign keys रखती है।

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // junction टेबल नाम
        ->foreignPivotKey('post_id')       // इस एंटिटी की ओर इशारा करने वाली FK
        ->relatedPivotKey('tag_id')        // संबंधित एंटिटी की ओर इशारा करने वाली FK
        ->withPivot('role', 'joined_at')   // लोड करने के लिए अतिरिक्त pivot कॉलम
        ->withPivotTimestamps()            // pivot पर created_at / updated_at जोड़ता है
        ->orderByPivot('joined_at', 'ASC');
}
```

Pivot data तक पहुँचना:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — role: ' . $pivot->get('role');
}
```

Pivot टेबल प्रबंधित करना:

```php
// pivot data के साथ एक tag attach करें
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// कई attach करें
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// एक detach करें
$em->relation($post, 'tags')->detach(tagId: 5);

// सभी detach करें
$em->relation($post, 'tags')->detach();

// Sync: पूरे pivot set को बदलें (हटाए गए detach, जोड़े गए attach)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Pivot data के साथ Sync
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// केवल जोड़ें, कभी न हटाएं
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Toggle: अनुपस्थित होने पर attach, उपस्थित होने पर detach
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Polymorphic रिलेशन्स एक रिलेशन को एक से अधिक एंटिटी टाइप को target करने की अनुमति देते हैं। "morph" टेबल पर दो कॉलम parent की पहचान करते हैं:

- `{name}_type` — एंटिटी क्लास (या एक configured alias) स्टोर करता है
- `{name}_id` — प्राइमरी की स्टोर करता है

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← जो भी टेबल हो उसमें FK
url
```

मैपर — owning side (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post में एक cover image होती है
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // imageable_type + imageable_id में resolve होता है
        ->localKey('id');

    // Post में कई images होती हैं
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

मैपर — morphable side (Image):

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

`HasOneThrough` एक single related एंटिटी को resolve करने के लिए दो टेबल traverse करता है। एक `User` के पास अपने `Phone` के **माध्यम से** एक `Carrier` होता है।

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
        firstKey:   'user_id',      // phones पर FK जो users की ओर इशारा करती है
        secondKey:  'carrier_id',   // phones पर FK जो carriers की ओर इशारा करती है
        localKey:   'id',           // users पर PK
        throughKey: 'id',           // carriers पर PK
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

जनरेट किया गया SQL एकल `JOIN` का उपयोग करता है:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` एक intermediate एंटिटी के माध्यम से एक दूर के collection तक पहुँच प्रदान करता है। एक `Country` के अपने `User`s के माध्यम से कई `Post`s होते हैं।

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // users पर FK जो countries की ओर इशारा करती है
        secondKey:  'user_id',      // posts पर FK जो users की ओर इशारा करती है
        localKey:   'id',           // countries पर PK
        throughKey: 'id',           // users पर PK
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// constraint के साथ: केवल published posts
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Eager loading

### बुनियादी eager loading

किसी भी repository मेथड के `with:` parameter में relation नाम pass करें:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver collections के लिए joins कभी नहीं, **`IN` clauses के साथ अलग queries** का उपयोग करता है — row multiplication से बचने के लिए।

### Nested relations के लिए dot-notation

```php
// users → posts → comments → comment authors लोड करें
// users की संख्या के बावजूद कुल 4 queries
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Constrained eager loading

लोड समय पर एक relation filter या sort करने के लिए एक closure pass करें:

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

### प्रति parent एंटिटी limit

```php
// प्रति user अधिकतम 3 posts लोड करें (समर्थित engines पर LATERAL JOIN उपयोग करता है)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Relation aggregates (लोड किए बिना)

पूरे relation को fetch किए बिना एंटिटीज़ में aggregate values संलग्न करें:

```php
// posts_count virtual property जोड़ें
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} के {$user->postsCount} posts हैं";
}
```

```php
// एक call में कई aggregates
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

## अस्तित्व क्वेरीज़

```php
// कम से कम एक post वाले users
$users = $userRepository->query()->has('posts')->get();

// बिना post वाले users
$users = $userRepository->query()->doesntHave('posts')->get();

// 5 से अधिक posts वाले users
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// ऐसे users जिनके posts में कम से कम एक published comment है
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Cascade विकल्प

| विकल्प | प्रभाव |
|---|---|
| `CascadeType::Persist` | owning side persist होने पर related entities persist करें |
| `CascadeType::Remove` | owning side delete होने पर related entities delete करें |
| `->orphanRemoval(true)` | collection से हटाए गए HasMany members को delete करें |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Cascades को स्पष्ट रूप से opt in किया जाना चाहिए। Weaver कभी चुपके से cascade नहीं करता।
:::

---

## Self-referencing रिलेशन्स

एंटिटीज़ जो अपनी ही टेबल को reference करती हैं (categories, menus, org charts):

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
// तीन स्तर गहरे लोड करें: children → grandchildren → great-grandchildren
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// वैकल्पिक dot-notation syntax
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
