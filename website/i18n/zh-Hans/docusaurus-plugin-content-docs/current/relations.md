---
id: relations
title: 关联关系
---

Weaver ORM 通过 `RelationMap` 在实体的**映射器类**中定义所有关联元数据。实体属性上没有任何注解，也没有运行时反射。关联关系始终**显式**加载——Weaver 从不在背后偷偷发出查询。

## 概览

### 拥有端与反向端

每个关联都有一个**拥有端（owning side）**和一个**反向端（inverse side）**。

- **拥有端**在其表中（或多对多的中间表中）保存外键。它控制关联的持久化。
- **反向端**通过 `mappedBy` 声明，指向拥有端。仅对反向端所做的更改**不会**写入数据库。

### 外键位置规则

| 关联类型 | 外键位置 | 映射器方法 |
|---|---|---|
| 一对一 | 在"另一个"实体的表上 | `hasOne` |
| 一对多 | 在"多"端实体的表上 | `hasMany` |
| 多对一 | 在**本**实体的表上 | `belongsTo` |
| 多对多 | 专用的中间表 | `belongsToMany` |
| 多态一对一 | 在可变形实体的表上 | `morphOne` |
| 多态一对多 | 在可变形实体的表上 | `morphMany` |

### 声明关联的方式

关联在映射器的 `relations(RelationMap $map)` 方法中注册：

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

## HasOne（一对一）

`HasOne` 表示一对一关联，外键位于**另一个**实体的表上。一个 `User` 拥有一个 `Profile`；`profiles` 表保存 `user_id`。

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

映射器（拥有端是 `ProfileMapper`；`UserMapper` 持有反向端）：

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // profiles 表上的列
        ->localKey('id')          // users 表上的列（主键）
        ->mappedBy('user');       // Profile 上的反向属性名
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

预加载：

```php
// 额外一条 IN 查询 — 永不 N+1
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// 批量加载多个用户的 profile（单条 IN 查询）
$users = $repository->findAll(with: ['profile']);
```

级联持久化：

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'Software engineer');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// 先插入 users 行，再插入带正确 user_id 的 profiles 行
```

---

## HasMany（一对多）

`HasMany` 表示一对多关联，外键位于**多**端。一个 `User` 拥有多个 `Post`；`posts` 表保存 `user_id`。

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
        ->foreignKey('user_id')          // posts 表上的列
        ->localKey('id')                 // users 表上的列
        ->orderBy('created_at', 'DESC')  // 默认排序
        ->orphanRemoval(true);           // 删除从集合中移除的 post
}
```

操作集合：

```php
// 预加载
$user = $repository->findById(1, with: ['posts']);

// 添加
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// 移除（启用 orphanRemoval：自动发出 DELETE）
$user->posts->remove($postToDelete);
$em->flush();

// 内存中过滤
$published = $user->posts->filter(fn(Post $p) => $p->published);

// 不加载数据直接计数
$count = $repository->countRelation($user, 'posts');
```

按字段为集合建立索引：

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // EntityCollection 以 post.id 为键

$post = $user->posts->get(42);
```

---

## BelongsTo（多对一）

`BelongsTo` 表示多对一关联，外键位于**本**实体的表上。一个 `Post` 属于一个 `User`；`posts` 表保存 `user_id`。

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // posts 表（本实体）上的列
        ->ownerKey('id');         // users 表上的主键
}
```

可选（可空）外键 — 无归属者的访客评论：

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

预加载：

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany（多对多）

`BelongsToMany` 表示由**中间表（Pivot Table）**支撑的多对多关联。一个 `Post` 可以有多个 `Tag`；`post_tag` 表保存两个外键。

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // 中间表名
        ->foreignPivotKey('post_id')       // 指向本实体的外键
        ->relatedPivotKey('tag_id')        // 指向关联实体的外键
        ->withPivot('role', 'joined_at')   // 要加载的额外中间列
        ->withPivotTimestamps()            // 在中间表上添加 created_at / updated_at
        ->orderByPivot('joined_at', 'ASC');
}
```

访问中间表数据：

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — role: ' . $pivot->get('role');
}
```

管理中间表：

```php
// 附加一个带中间数据的标签
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// 附加多个
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// 分离一个
$em->relation($post, 'tags')->detach(tagId: 5);

// 分离全部
$em->relation($post, 'tags')->detach();

// 同步：替换整个中间集合（分离已移除的，附加新增的）
$em->relation($post, 'tags')->sync([3, 7, 11]);

// 带中间数据的同步
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// 仅添加，从不移除
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// 切换：不存在则附加，存在则分离
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany（多态关联）

多态关联允许单个关联指向多种实体类型。"变形"表上的两列标识父实体：

- `{name}_type` — 存储实体类（或配置的别名）
- `{name}_id` — 存储主键

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← 指向相应表的外键
url
```

映射器 — 拥有端（Post）：

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post 拥有一张封面图片
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // 解析为 imageable_type + imageable_id
        ->localKey('id');

    // Post 拥有多张图片
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

映射器 — 可变形端（Image）：

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // 别名 → 类映射
            'user' => User::class,
        ]);
}
```

查询：

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough（通过中间表的一对一）

`HasOneThrough` 通过两张表解析单个关联实体。一个 `User` 通过其 `Phone` 拥有一个 `Carrier`。

```
users       phones           carriers
──────      ──────────────   ──────────
id          id               id
name        user_id  (外键)   name
            carrier_id (外键)
```

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOneThrough(
        relation:   'carrier',
        related:    Carrier::class,
        through:    Phone::class,
        firstKey:   'user_id',      // phones 上指向 users 的外键
        secondKey:  'carrier_id',   // phones 上指向 carriers 的外键
        localKey:   'id',           // users 上的主键
        throughKey: 'id',           // carriers 上的主键
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

生成的 SQL 使用单个 `JOIN`：

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough（通过中间表的一对多）

`HasManyThrough` 通过中间实体提供对远端集合的访问。一个 `Country` 通过其 `User` 拥有多个 `Post`。

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // users 上指向 countries 的外键
        secondKey:  'user_id',      // posts 上指向 users 的外键
        localKey:   'id',           // countries 上的主键
        throughKey: 'id',           // users 上的主键
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// 带约束：仅已发布的帖子
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## 预加载（Eager Loading）

### 基础预加载

将关联名称传递给任何仓储方法的 `with:` 参数：

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver 使用**带 `IN` 子句的独立查询**——而非用于集合的 `JOIN`——以避免行数乘积。

### 点符号表示嵌套关联

```php
// 加载 users → posts → comments → comment authors
// 总计恰好 4 条查询，与用户数量无关
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### 带约束的预加载

传递闭包以在加载时过滤或排序关联：

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

嵌套约束：

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

### 每个父实体的数量限制

```php
// 每个用户最多加载 3 篇帖子（在支持的引擎上使用 LATERAL JOIN）
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## 关联聚合（无需加载）

为实体附加聚合值，而无需获取完整关联：

```php
// 添加 posts_count 虚拟属性
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} has {$user->postsCount} posts";
}
```

```php
// 一次调用中的多个聚合
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

带约束的聚合：

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

## 存在性查询

```php
// 至少有一篇帖子的用户
$users = $userRepository->query()->has('posts')->get();

// 没有帖子的用户
$users = $userRepository->query()->doesntHave('posts')->get();

// 帖子数超过 5 的用户
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// 拥有至少一条已审核评论的帖子的用户
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## 级联选项

| 选项 | 效果 |
|---|---|
| `CascadeType::Persist` | 当拥有端持久化时，持久化关联实体 |
| `CascadeType::Remove` | 当拥有端删除时，删除关联实体 |
| `->orphanRemoval(true)` | 删除从集合中移除的 HasMany 成员 |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
级联必须显式选择启用。Weaver 从不静默地进行级联。
:::

---

## 自引用关联

实体引用自身所在表（分类、菜单、组织架构图）：

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

递归预加载（有界深度）：

```php
// 加载三层深度：children → grandchildren → great-grandchildren
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// 替代的点符号语法
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
