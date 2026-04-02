<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Entity;

class Post
{
    public ?int $id = null;
    public string $title = '';
    public string $status = 'draft';
    public ?int $userId = null;
    public ?User $user = null;
    public array $comments = [];
    public ?\DateTimeImmutable $deletedAt = null;
}
