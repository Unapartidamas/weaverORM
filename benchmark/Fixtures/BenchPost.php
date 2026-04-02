<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;

#[Entity(table: 'bench_posts')]
class BenchPost
{
    #[Id]
    public int $id = 0;

    #[Column]
    public int $userId = 0;

    #[Column]
    public string $title = '';

    #[Column]
    public string $body = '';
}
