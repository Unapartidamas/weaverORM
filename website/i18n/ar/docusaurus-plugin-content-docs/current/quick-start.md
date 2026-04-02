---
id: quick-start
title: البدء السريع
---

يستعرض هذا الدليل مثالاً كاملاً وعملياً: تعريف كيان `User`، وكتابة مُعيِّنه، وتنفيذ عمليات الاستمرار الأساسية باستخدام `EntityWorkspace`.

## الخطوة 1 — تعريف الكيان

الكيان هو صف PHP عادي. لا يستورد أي شيء من `Weaver\ORM`.

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

    public function deactivate(): self
    {
        return new self(
            id:        $this->id,
            name:      $this->name,
            email:     $this->email,
            isActive:  false,
            createdAt: $this->createdAt,
        );
    }
}
```

لاحظ: لا `use Doctrine\...`، ولا خصائص `#[ORM\...]`، ولا صف أساسي. الكيان هو كائن قيمة PHP خالص يمكن اختباره دون تشغيل Symfony أو الاتصال بقاعدة بيانات.

## الخطوة 2 — كتابة المُعيِّن

المُعيِّن هو صف منفصل يخبر Weaver كيف يُعيَّن `User` إلى جدول `users`. يمتد من `AbstractMapper` ويُنفّذ أربع طرق.

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

يُكتشَف المُعيِّنات التي تمتد من `AbstractMapper` وتُسجَّل تلقائياً بواسطة Weaver عند تفعيل `autoconfigure: true` في `config/services.yaml` (الإعداد الافتراضي لـ Symfony).

## الخطوة 3 — إنشاء جدول قاعدة البيانات

```bash
docker compose exec app bin/console weaver:schema:create
```

لمعاينة SQL دون تنفيذه:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## الخطوة 4 — كتابة مستودع

امتد من `AbstractRepository` للحصول على واجهة برمجة استعلامات مصنّفة وقابلة لإعادة الاستخدام لكيانك.

```php
<?php
// src/Repository/UserRepository.php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Mapper\UserMapper;
use Weaver\ORM\Repository\AbstractRepository;

/** @extends AbstractRepository<User> */
final class UserRepository extends AbstractRepository
{
    protected function mapper(): UserMapper
    {
        return $this->get(UserMapper::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()
            ->where('email', '=', $email)
            ->first();
    }

    /** @return User[] */
    public function findActive(): array
    {
        return $this->query()
            ->where('is_active', '=', true)
            ->orderBy('name', 'ASC')
            ->get();
    }
}
```

يوفر `AbstractRepository` طرق `findById()` و`findAll()` و`save()` و`delete()` و`query()` جاهزة للاستخدام.

## الخطوة 5 — استخدام EntityWorkspace للاستمرار

في المتحكم أو الأمر أو الخدمة، أدخل `UserRepository` (واختيارياً `EntityWorkspace` للتحكم المباشر في وحدة العمل).

```php
<?php
// src/Controller/UserController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    // --- إنشاء ---
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        $user = new User(
            id:        null,
            name:      $data['name'],
            email:     $data['email'],
            isActive:  true,
            createdAt: new DateTimeImmutable(),
        );

        $this->users->save($user);

        return $this->json(['id' => $user->id], Response::HTTP_CREATED);
    }

    // --- قراءة ---
    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'is_active'  => $user->isActive,
            'created_at' => $user->createdAt->format('c'),
        ]);
    }

    // --- تحديث ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // الكيانات ثابتة — أنشئ نسخة جديدة واحفظها
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- حذف ---
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->users->delete($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
```

## ما يحدث خلف الكواليس

عند استدعاء `$this->users->save($user)`:

1. يستدعي Weaver `dehydrate($user)` على مُعيِّنك للحصول على مصفوفة أزواج عمود → قيمة.
2. إذا كان `id` هو `null`، يُصدر `INSERT` ويكتب المعرف المولَّد مرة أخرى على الكيان.
3. إذا كان `id` محدداً، يُصدر `UPDATE ... WHERE id = ?`.
4. لا تتبع للتغييرات، لا فحص للاختلافات، لا صفوف وكيل. الدلالات صريحة.

## الخطوات التالية

- [تعيين الكيانات](/entity-mapping) — جميع أنواع الأعمدة وخيارات المفتاح الأساسي وإعداد المُعيِّن
- [العلاقات](/relations) — HasOne وHasMany وBelongsTo وBelongsToMany والمزيد
- [إعداد Symfony](/configuration) — مرجع كامل لـ `weaver.yaml` مع نسخ القراءة وخيارات التصحيح
