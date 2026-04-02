<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Entity;

class Profile
{
    public ?int $id = null;
    public ?int $userId = null;
    public string $bio = '';
    public ?User $user = null;
}
