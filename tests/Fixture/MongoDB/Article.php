<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\MongoDB;

class Article
{
    public ?string $id = null;
    public string $title = '';
    public string $status = '';
    public int $views = 0;
    public float $score = 0.0;
    public bool $published = false;
    public ?string $authorId = null;
    public array $tags = [];
}
