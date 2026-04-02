<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'doctrine_bench_posts')]
class DoctrinePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'integer')]
    public int $userId = 0;

    #[ORM\Column(type: 'string', length: 255)]
    public string $title = '';

    #[ORM\Column(type: 'string', length: 1000)]
    public string $body = '';
}
