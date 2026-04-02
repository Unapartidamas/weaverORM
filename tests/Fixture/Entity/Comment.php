<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Entity;

class Comment
{
    public ?int $id = null;
    public ?int $postId = null;
    public string $body = '';
    public ?Post $post = null;
}
