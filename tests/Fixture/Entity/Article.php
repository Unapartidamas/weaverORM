<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Entity;

use Weaver\ORM\Mapping\Attribute\BelongsTo;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\HasMany;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\Timestamps;

#[Entity(table: 'articles')]
#[Timestamps]
class Article
{
    #[Id]
    public int $id = 0;

    #[Column]
    public string $title = '';

    #[Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[BelongsTo(User::class, foreignKey: 'user_id')]
    public mixed $author = null;

    #[HasMany(Comment::class, foreignKey: 'article_id')]
    public mixed $comments = null;
}
