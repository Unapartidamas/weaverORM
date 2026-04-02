<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Entity;

class User
{
    public ?int $id = null;
    public string $email = '';
    public string $name = '';
    public string $role = 'user';
    public bool $active = true;
    public ?\DateTimeImmutable $createdAt = null;

    public array $posts = [];
    public ?Profile $profile = null;
}
