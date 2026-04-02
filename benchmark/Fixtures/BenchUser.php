<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;

#[Entity(table: 'bench_users')]
class BenchUser
{
    #[Id]
    public int $id = 0;

    #[Column]
    public string $name = '';

    #[Column]
    public string $email = '';

    #[Column]
    public int $age = 0;

    #[Column]
    public string $status = 'active';
}
