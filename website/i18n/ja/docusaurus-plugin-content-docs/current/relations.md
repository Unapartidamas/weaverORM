---
id: relations
title: リレーション
---

Weaver ORM は、`RelationMap` を介してエンティティの**マッパークラス**内にすべてのリレーションメタデータを定義します。エンティティプロパティへのアトリビュートはなく、実行時リフレクションもありません。リレーションは常に**明示的に**ロードされます — Weaver は裏でサプライズクエリを発行することはありません。

## 概要

### オーナー側と逆側

すべてのリレーションには1つの**オーナー側**と1つの**逆側**があります。

- **オーナー側**はそのテーブル（または多対多の場合はピボットテーブル）に外部キーを持ちます。関連付けの永続化を制御します。
- **逆側**は `mappedBy` でオーナー側を指し示して宣言されます。逆側のみに加えた変更はデータベースに書き込まれ**ません**。

### 外部キーの配置ルール

| リレーション型 | FK の場所 | マッパーメソッド |
|---|---|---|
| 1対1 | 「他の」エンティティのテーブル | `hasOne` |
| 1対多 | 「多」エンティティのテーブル | `hasMany` |
| 多対1 | **このエンティティ**のテーブル | `belongsTo` |
| 多対多 | 専用のピボットテーブル | `belongsToMany` |
| ポリモーフィック1対1 | モーファブルエンティティのテーブル | `morphOne` |
| ポリモーフィック1対多 | モーファブルエンティティのテーブル | `morphMany` |

### リレーションの宣言方法

リレーションはマッパーの `relations(RelationMap $map)` メソッド内で登録されます：

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

## HasOne（1対1）

`HasOne` は、外部キーが**他の**エンティティのテーブルにある1対1のリレーションシップを表します。`User` は1つの `Profile` を持ち、`profiles` テーブルが `user_id` を持ちます。

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

マッパー（オーナー側は `ProfileMapper`；`UserMapper` は逆側を持つ）：

```php
// src/Mapper/UserMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasOne('profile', Profile::class)
        ->foreignKey('user_id')   // profiles テーブルのカラム
        ->localKey('id')          // users テーブルのカラム（PK）
        ->mappedBy('user');       // Profile の逆側プロパティ名
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

イーガーロード：

```php
// 追加の IN クエリ1回 — N+1 なし
$user = $repository->findById(1, with: ['profile']);
echo $user->profile?->bio;

// 多数のユーザーのプロファイルを一括ロード（単一の IN クエリ）
$users = $repository->findAll(with: ['profile']);
```

カスケード永続化：

```php
$user    = new User(id: 0, email: 'alice@example.com', name: 'Alice');
$profile = new Profile(id: 0, userId: 0, bio: 'ソフトウェアエンジニア');

$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
// まず users 行を挿入し、次に正しい user_id で profiles 行を挿入
```

---

## HasMany（1対多）

`HasMany` は、外部キーが**多**側にある1対多のリレーションシップを表します。`User` は多数の `Post` を持ち、`posts` テーブルが `user_id` を持ちます。

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
        ->foreignKey('user_id')          // posts テーブルのカラム
        ->localKey('id')                 // users テーブルのカラム
        ->orderBy('created_at', 'DESC')  // デフォルトの並び順
        ->orphanRemoval(true);           // コレクションから削除された投稿を削除
}
```

コレクションの操作：

```php
// イーガーロード
$user = $repository->findById(1, with: ['posts']);

// 追加
$user->posts->add(new Post(...));
$em->flush(); // INSERT

// 削除（orphanRemoval あり：DELETE が自動的に発行される）
$user->posts->remove($postToDelete);
$em->flush();

// インメモリでフィルタリング
$published = $user->posts->filter(fn(Post $p) => $p->published);

// ロードせずにカウント
$count = $repository->countRelation($user, 'posts');
```

フィールドでコレクションにインデックスを付ける：

```php
$map->hasMany('posts', Post::class)
    ->foreignKey('user_id')
    ->indexBy('id');   // post.id でキー付けされた EntityCollection

$post = $user->posts->get(42);
```

---

## BelongsTo（多対1）

`BelongsTo` は、外部キーが**このエンティティ**のテーブルにある多対1のリレーションシップを表します。`Post` は `User` に属し、`posts` テーブルが `user_id` を持ちます。

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsTo('author', User::class)
        ->foreignKey('user_id')   // posts テーブルのカラム（このエンティティ）
        ->ownerKey('id');         // users テーブルの PK
}
```

オプション（nullable）FK — オーナーなしのゲストコメント：

```php
$map->belongsTo('author', User::class)
    ->foreignKey('user_id')
    ->ownerKey('id')
    ->nullable(true);
```

イーガーロード：

```php
$posts = $postRepository->findAll(with: ['author']);

foreach ($posts as $post) {
    echo "{$post->author->name}: {$post->title}";
}
```

---

## BelongsToMany（多対多）

`BelongsToMany` は、**ピボット**（ジャンクション）テーブルに支えられた多対多のリレーションシップを表します。`Post` は多数の `Tag` を持てます；`post_tag` テーブルが両方の外部キーを保持します。

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    $map->belongsToMany('tags', Tag::class)
        ->pivotTable('post_tag')           // ジャンクションテーブル名
        ->foreignPivotKey('post_id')       // このエンティティを指す FK
        ->relatedPivotKey('tag_id')        // 関連エンティティを指す FK
        ->withPivot('role', 'joined_at')   // ロードする追加のピボットカラム
        ->withPivotTimestamps()            // ピボットに created_at / updated_at を追加
        ->orderByPivot('joined_at', 'ASC');
}
```

ピボットデータへのアクセス：

```php
$post = $postRepository->findById(1, with: ['tags']);

foreach ($post->tags as $tag) {
    $pivot = $tag->pivot();
    echo $tag->name . ' — ロール: ' . $pivot->get('role');
}
```

ピボットテーブルの管理：

```php
// ピボットデータと共に1つのタグをアタッチ
$em->relation($post, 'tags')->attach(tagId: 5, pivot: ['role' => 'primary']);

// 複数をアタッチ
$em->relation($post, 'tags')->attach([
    5 => ['role' => 'primary'],
    8 => ['role' => 'secondary'],
]);

// 1つをデタッチ
$em->relation($post, 'tags')->detach(tagId: 5);

// すべてをデタッチ
$em->relation($post, 'tags')->detach();

// 同期：ピボットセット全体を置き換える（削除されたものはデタッチ、追加されたものはアタッチ）
$em->relation($post, 'tags')->sync([3, 7, 11]);

// ピボットデータと共に同期
$em->relation($post, 'tags')->sync([
    3 => ['role' => 'primary'],
    7 => ['role' => 'secondary'],
]);

// 追加のみ、削除なし
$em->relation($post, 'tags')->syncWithoutDetaching([15, 16]);

// トグル：ない場合はアタッチ、ある場合はデタッチ
$em->relation($post, 'tags')->toggle(tagId: 5);
```

---

## MorphOne / MorphMany（ポリモーフィック）

ポリモーフィックリレーションは、1つのリレーションが複数のエンティティ型を対象にできます。「モーフ」テーブルの2つのカラムが親を識別します：

- `{name}_type` — エンティティクラス（または設定されたエイリアス）を格納
- `{name}_id` — プライマリキーを格納

```
images
──────────────────────
id
imageable_type    ← 'App\Entity\Post' | 'App\Entity\User'
imageable_id      ← どちらのテーブルへの FK
url
```

マッパー — オーナー側（Post）：

```php
// src/Mapper/PostMapper.php
protected function relations(RelationMap $map): void
{
    // Post には1つのカバー画像がある
    $map->morphOne('coverImage', Image::class)
        ->morphName('imageable')   // imageable_type + imageable_id に解決される
        ->localKey('id');

    // Post には多数の画像がある
    $map->morphMany('images', Image::class)
        ->morphName('imageable')
        ->localKey('id');
}
```

マッパー — モーファブル側（Image）：

```php
// src/Mapper/ImageMapper.php
protected function relations(RelationMap $map): void
{
    $map->morphTo('imageable')
        ->morphName('imageable')
        ->morphMap([
            'post' => Post::class,   // エイリアス → クラスのマッピング
            'user' => User::class,
        ]);
}
```

クエリ：

```php
$posts = $postRepository->findAll(with: ['coverImage', 'images']);

$images = $imageRepository->findWhere([
    'imageable_type' => Post::class,
    'imageable_id'   => $post->id,
]);
```

---

## HasOneThrough（中間テーブルを介した1対1）

`HasOneThrough` は2つのテーブルを横断して1つの関連エンティティを解決します。`User` は `Phone` を**通じて**1つの `Carrier` を持ちます。

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
        firstKey:   'user_id',      // users を指す phones の FK
        secondKey:  'carrier_id',   // carriers を指す phones の FK
        localKey:   'id',           // users の PK
        throughKey: 'id',           // carriers の PK
    );
}
```

```php
$user = $userRepository->findById(1, with: ['carrier']);
echo $user->carrier?->name; // 'Verizon'
```

生成される SQL は単一の `JOIN` を使用します：

```sql
SELECT carriers.*
FROM   carriers
INNER JOIN phones ON phones.carrier_id = carriers.id
WHERE  phones.user_id IN (1, 2, 3)
```

---

## HasManyThrough（中間テーブルを介した1対多）

`HasManyThrough` は中間エンティティを通じて遠くのコレクションへのアクセスを提供します。`Country` は `User` を通じて多数の `Post` を持ちます。

```php
// src/Mapper/CountryMapper.php
protected function relations(RelationMap $map): void
{
    $map->hasManyThrough(
        relation:   'posts',
        related:    Post::class,
        through:    User::class,
        firstKey:   'country_id',   // countries を指す users の FK
        secondKey:  'user_id',      // users を指す posts の FK
        localKey:   'id',           // countries の PK
        throughKey: 'id',           // users の PK
    );
}
```

```php
$country = $countryRepository->findById(1, with: ['posts']);

// 制約付き：公開済みの投稿のみ
$country = $countryRepository->findById(1, with: [
    'posts' => fn($q) => $q->where('published', true)->orderBy('created_at', 'DESC'),
]);
```

---

## イーガーロード

### 基本的なイーガーロード

リポジトリメソッドの `with:` パラメーターにリレーション名を渡します：

```php
$user  = $repository->findById(1, with: ['profile', 'posts']);
$users = $repository->findAll(with: ['profile']);
```

Weaver は行の増殖を避けるため、コレクションには `JOIN` ではなく **`IN` 句を使用した個別クエリ**を使用します。

### ネストされたリレーションのドット記法

```php
// ユーザー → 投稿 → コメント → コメント著者をロード
// ユーザー数に関係なく合計4クエリのみ
$users = $userRepository->findAll(
    with: ['posts.comments.author'],
);
```

### 制約付きイーガーロード

ロード時にリレーションをフィルタリングまたはソートするためにクロージャを渡します：

```php
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->where('published', true)
          ->orderBy('created_at', 'DESC'),
]);
```

ネストされた制約：

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

### 親エンティティごとの件数制限

```php
// ユーザーごとに最大3件の投稿をロード（対応エンジンでは LATERAL JOIN を使用）
$users = $userRepository->findAll(with: [
    'posts' => fn(RelationQuery $q) =>
        $q->orderBy('created_at', 'DESC')
          ->limitPerGroup(3),
]);
```

---

## リレーション集計（ロードなし）

完全なリレーションを取得せずに集計値をエンティティにアタッチします：

```php
// posts_count 仮想プロパティを追加
$users = $userRepository->findAll(withCount: ['posts']);

foreach ($users as $user) {
    echo "{$user->name} は {$user->postsCount} 件の投稿があります";
}
```

```php
// 1回の呼び出しで複数の集計
$users = $userRepository->findAll(
    withCount: ['posts'],
    withSum:   [['orders', 'total']],
    withMax:   [['orders', 'total']],
    withAvg:   [['orders', 'total']],
);
```

制約付き集計：

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

## 存在クエリ

```php
// 少なくとも1つの投稿を持つユーザー
$users = $userRepository->query()->has('posts')->get();

// 投稿を持たないユーザー
$users = $userRepository->query()->doesntHave('posts')->get();

// 5件以上の投稿を持つユーザー
$users = $userRepository->query()->has('posts', '>=', 5)->get();

// 承認済みコメントが少なくとも1つある投稿を持つユーザー
$users = $userRepository->query()
    ->whereHas('posts', fn($q) => $q->whereHas('comments', fn($cq) =>
        $cq->where('approved', true)
    ))
    ->get();
```

---

## カスケードオプション

| オプション | 効果 |
|---|---|
| `CascadeType::Persist` | オーナー側が永続化される際に関連エンティティも永続化する |
| `CascadeType::Remove` | オーナー側が削除される際に関連エンティティも削除する |
| `->orphanRemoval(true)` | コレクションから削除された HasMany メンバーを削除する |

```php
$em->persist($user, cascade: [CascadeType::Persist]);
$em->flush();
```

:::warning
カスケードは明示的にオプトインする必要があります。Weaver は暗黙的にカスケードしません。
:::

---

## 自己参照リレーション

自分のテーブルを参照するエンティティ（カテゴリ、メニュー、組織図）：

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

再帰的なイーガーロード（深さ制限付き）：

```php
// 3レベル深くロード：子 → 孫 → 曾孫
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children' => fn($q) => $q->withRecursive(depth: 3)],
);

// 代替のドット記法構文
$roots = $categoryRepository->findWhere(
    criteria: ['parent_id' => null],
    with: ['children.children.children'],
);
```
