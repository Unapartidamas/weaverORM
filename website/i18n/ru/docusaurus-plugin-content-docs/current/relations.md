---
id: relations
title: Связи
---

Weaver ORM определяет все метаданные связей внутри **класса-маппера** сущности через `RelationMap`. На свойствах сущностей нет атрибутов, нет рефлексии во время выполнения. Связи всегда загружаются **явно** — Weaver никогда не выполняет скрытые запросы за вашей спиной.

## Обзор

### Владеющая и обратная стороны

Каждая связь имеет одну **владеющую сторону** и одну **обратную сторону**.

- **Владеющая сторона** хранит внешний ключ в своей таблице (или в сводной таблице для many-to-many). Она управляет сохранением связи.
- **Обратная сторона** объявляется с `mappedBy`, указывающим на владеющую сторону. Изменения, внесённые только на обратной стороне, **не** записываются в базу данных.

### Правила размещения внешних ключей

| Тип связи | Расположение FK | Метод маппера |
|---|---|---|
| Один-к-одному | В таблице «другой» сущности | `hasOne` |
| Один-ко-многим | В таблице «многих» сущностей | `hasMany` |
| Многие-к-одному | В таблице **этой** сущности | `belongsTo` |
| Многие-ко-многим | Выделенная сводная таблица | `belongsToMany` |
| Полиморфный один-к-одному | В таблице морфируемой сущности | `morphOne` |
| Полиморфный один-ко-многим | В таблице морфируемой сущности | `morphMany` |

### Объявление связей

Связи регистрируются внутри метода `relations(RelationMap $map)` маппера:

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

`HasOne` представляет связь один-к-одному, где внешний ключ находится в таблице **другой** сущности. `User` имеет один `Profile`; таблица `profiles` содержит `user_id`.

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

Маппер (владеющая сторона — `ProfileMapper`; `UserMapper` хранит обратную):

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // колонка в таблице profiles
        ->localKey('id')          // колонка в таблице users (PK)
        ->mappedBy('user');       // имя обратного свойства в Profile
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

Жадная загрузка:

```php
// Один дополнительный IN-запрос — никогда не N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// Пакетная загрузка профилей для многих пользователей (один IN-запрос)
$users = $repository->findAll(with: ['profile']);
```

Каскадное сохранение:

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Software engineer');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// Сначала вставляет строку в users, затем строку в profiles с правильным user_id
```

---

## HasMany

`HasMany` представляет связь один-ко-многим, где внешний ключ находится на **стороне «многих»**. `User` имеет много `Post`; таблица `posts` содержит `user_id`.

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
        ->foreignKey('user_id')          // колонка в таблице posts
        ->localKey('id')                 // колонка в таблице users
        ->orderBy('created_at', 'DESC')  // сортировка по умолчанию
        ->orphanRemoval(true);           // удалять посты, убранные из коллекции
}
```

Работа с коллекцией:

```php
// Жадная загрузка
$user = $repository->findById(1, with: ['posts']);

// Добавление
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// Удаление (с orphanRemoval: DELETE выполняется автоматически)
$user->posts->remove($postToDelete);
$em->flush();

// Фильтрация в памяти
$published = $user->posts->filter(fn(Post $p) => $p->published);

// Подсчёт без загрузки
$count = $repository->countRelation($user, 'posts');
```

Индексирование коллекции по полю:

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection, индексированная по post.id

$post = $user->posts->get(42);
```

---

## BelongsTo

`BelongsTo` представляет связь многие-к-одному, где внешний ключ находится в таблице **этой** сущности. `Post` принадлежит `User`; таблица `posts` содержит `user_id`.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // колонка в таблице posts (эта сущность)
        ->ownerKey('id');         // PK в таблице users
}
```

Необязательный (nullable) FK — гостевые комментарии без владельца:

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

Жадная загрузка:

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany

`BelongsToMany` представляет связь многие-ко-многим через **сводную** (промежуточную) таблицу. `Post` может иметь много `Tag`; таблица `post_tag` хранит оба внешних ключа.

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // имя промежуточной таблицы
        ->foreignPivotKey('post_id')       // FK, указывающий на эту сущность
        ->relatedPivotKey('tag_id')        // FK, указывающий на связанную сущность
        ->withPivot('role', 'joined_at')   // дополнительные колонки сводной таблицы для загрузки
        ->withPivotTimestamps()            // добавляет created_at / updated_at на сводной таблице
        ->orderByPivot('joined_at', 'ASC');
}
```

Доступ к данным сводной таблицы:

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — роль: ' . $pivot->get('role');
}
```

Управление сводной таблицей:

```php
// Прикрепить один тег с данными сводной таблицы
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// Прикрепить несколько
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// Отсоединить один
$em->relation($post, 'tags')->detach(tagId: 5);

// Отсоединить все
$em->relation($post, 'tags')->detach();

// Синхронизация: заменить весь набор (отсоединить убранные, прикрепить добавленные)
$em->relation($post, 'tags')->sync([3, 7, 11]);

// Синхронизация с данными сводной таблицы
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// Только добавлять, никогда не удалять
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// Переключение: прикрепить если отсутствует, отсоединить если присутствует
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany

Полиморфные связи позволяют одной связи ссылаться на более чем один тип сущности. Две колонки в «морф»-таблице идентифицируют родителя:

- `{name}_type` — хранит класс сущности (или настроенный псевдоним)
- `{name}_id` — хранит первичный ключ

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← FK в соответствующую таблицу
url
```

Маппер — владеющая сторона (Post):

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post имеет одно обложечное изображение
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // разрешается в imageable_type + imageable_id
        ->localKey('id');

    // Post имеет много изображений
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

Маппер — морфируемая сторона (Image):

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // псевдоним → маппинг класса
            'user' => User::class,
        ]);
}
```

Запросы:

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough

`HasOneThrough` проходит через две таблицы, чтобы получить одну связанную сущность. `User` имеет один `Carrier` **через** свой `Phone`.

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
        firstKey:   'user_id',      // FK на phones, указывающий на users
        secondKey:  'carrier_id',   // FK на phones, указывающий на carriers
        localKey:   'id',           // PK на users
        throughKey: 'id',           // PK на carriers
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

Генерируемый SQL использует один `JOIN`:

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough

`HasManyThrough` предоставляет доступ к удалённой коллекции через промежуточную сущность. `Country` имеет много `Post` через своих `User`.

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // FK на users, указывающий на countries
        secondKey:  'user_id',      // FK на posts, указывающий на users
        localKey:   'id',           // PK на countries
        throughKey: 'id',           // PK на users
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// С ограничением: только опубликованные посты
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## Жадная загрузка

### Базовая жадная загрузка

Передайте имена связей в параметр `with:` любого метода репозитория:

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver использует **отдельные запросы с условием `IN`** — никогда не `JOIN`-ы для коллекций — чтобы избежать умножения строк.

### Точечная нотация для вложенных связей

```php
// Загрузить users → posts → comments → авторы комментариев
// Ровно 4 запроса, независимо от количества пользователей
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### Жадная загрузка с ограничениями

Передайте замыкание для фильтрации или сортировки связи во время загрузки:

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

Вложенные ограничения:

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

### Ограничение на родительскую сущность

```php
// Загрузить не более 3 постов на пользователя (использует LATERAL JOIN на поддерживаемых движках)
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## Агрегаты связей (без загрузки)

Добавляйте агрегированные значения к сущностям без загрузки полной связи:

```php
// Добавить виртуальное свойство posts_count
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} имеет {$user->postsCount} постов";
}
```

```php
// Несколько агрегатов за один вызов
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

Агрегат с ограничением:

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

## Запросы на существование

```php
// Пользователи хотя бы с одним постом
$users = $userRepository->query()->has('posts')->get();

// Пользователи без постов
$users = $userRepository->query()->doesntHave('posts')->get();

// Пользователи с более чем 5 постами
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// Пользователи с постами, у которых есть хотя бы один одобренный комментарий
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## Варианты каскадирования

| Вариант | Эффект |
|---|---|
| `CascadeType::Persist` | Сохранять связанные сущности при сохранении владеющей стороны |
| `CascadeType::Remove` | Удалять связанные сущности при удалении владеющей стороны |
| `->orphanRemoval(true)` | Удалять элементы HasMany, удалённые из коллекции |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
Каскадирование должно быть явно разрешено. Weaver никогда не каскадирует неявно.
:::

---

## Самореферентные связи

Сущности, ссылающиеся на собственную таблицу (категории, меню, организационные структуры):

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

Рекурсивная жадная загрузка (с ограниченной глубиной):

```php
// Загрузить три уровня: дети → внуки → правнуки
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// Альтернативный синтаксис с точечной нотацией
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
