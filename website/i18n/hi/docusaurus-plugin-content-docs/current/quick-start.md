---
id: quick-start
title: त्वरित प्रारंभ
---

यह गाइड एक पूर्ण कार्यशील उदाहरण के माध्यम से चलता है: एक `User` एंटिटी परिभाषित करना, उसका मैपर लिखना, और `EntityWorkspace` का उपयोग करके बुनियादी persistence ऑपरेशन करना।

## चरण 1 — एंटिटी परिभाषित करें

एंटिटी एक सामान्य PHP क्लास है। यह `Weaver\ORM` से कुछ भी इम्पोर्ट नहीं करती।

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

ध्यान दें: कोई `use Doctrine\...` नहीं, कोई `#[ORM\...]` एट्रिब्यूट नहीं, कोई बेस क्लास नहीं। एंटिटी एक शुद्ध PHP वैल्यू ऑब्जेक्ट है जिसे Symfony बूट किए बिना या डेटाबेस से कनेक्ट हुए बिना यूनिट-टेस्ट किया जा सकता है।

## चरण 2 — मैपर लिखें

मैपर एक अलग क्लास है जो Weaver को बताती है कि `User` `users` टेबल से कैसे मैप होती है। यह `AbstractMapper` को एक्सटेंड करती है और चार मेथड लागू करती है।

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

`AbstractMapper` को एक्सटेंड करने वाले मैपर्स Weaver द्वारा स्वचालित रूप से पहचाने और पंजीकृत किए जाते हैं जब `config/services.yaml` में `autoconfigure: true` सक्षम होता है (Symfony डिफ़ॉल्ट)।

## चरण 3 — डेटाबेस टेबल बनाएं

```bash
docker compose exec app bin/console weaver:schema:create
```

SQL निष्पादित किए बिना प्रीव्यू करने के लिए:

```bash
docker compose exec app bin/console weaver:schema:create --dump-sql
```

## चरण 4 — एक रिपॉजिटरी लिखें

अपनी एंटिटी के लिए एक टाइप्ड, पुन: उपयोग योग्य क्वेरी API पाने के लिए `AbstractRepository` को एक्सटेंड करें।

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

`AbstractRepository` बॉक्स से बाहर `findById()`, `findAll()`, `save()`, `delete()`, और `query()` प्रदान करता है।

## चरण 5 — persistence के लिए EntityWorkspace का उपयोग करें

एक कंट्रोलर, कमांड, या सेवा में, `UserRepository` इंजेक्ट करें (और वैकल्पिक रूप से `EntityWorkspace` सीधे यूनिट-ऑफ-वर्क नियंत्रण के लिए)।

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

    // --- बनाएं ---
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

    // --- पढ़ें ---
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

    // --- अपडेट करें ---
    #[Route('/{id}/email', methods: ['PATCH'])]
    public function updateEmail(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        // एंटिटीज़ immutable हैं — एक नया संस्करण बनाएं और सेव करें
        $updated = $user->withEmail($request->toArray()['email']);
        $this->users->save($updated);

        return $this->json(['email' => $updated->email]);
    }

    // --- हटाएं ---
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

## हुड के नीचे क्या होता है

जब आप `$this->users->save($user)` कॉल करते हैं:

1. Weaver आपके मैपर पर `dehydrate($user)` कॉल करता है ताकि कॉलम → वैल्यू जोड़ों का एक एरे मिल सके।
2. यदि `id` `null` है, तो यह एक `INSERT` जारी करता है और जनरेट की गई ID को एंटिटी पर वापस लिखता है।
3. यदि `id` सेट है, तो यह `UPDATE ... WHERE id = ?` जारी करता है।
4. कोई चेंज-ट्रैकिंग नहीं, कोई dirty-check diff नहीं, कोई प्रॉक्सी क्लास नहीं। सेमेंटिक्स स्पष्ट हैं।

## अगले चरण

- [एंटिटी मैपिंग](/entity-mapping) — सभी कॉलम टाइप्स, प्राइमरी की विकल्प, और मैपर कॉन्फ़िगरेशन
- [रिलेशन्स](/relations) — HasOne, HasMany, BelongsTo, BelongsToMany, और अधिक
- [Symfony कॉन्फ़िगरेशन](/configuration) — रीड रेप्लिका और डीबग विकल्पों के साथ पूर्ण `weaver.yaml` संदर्भ
