<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bench_users')]
class DoctrineUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $name = '';

    #[ORM\Column(type: 'string', length: 255)]
    public string $email = '';

    #[ORM\Column(type: 'integer')]
    public int $age = 25;

    #[ORM\Column(name: 'created_at', type: 'string', length: 50)]
    public string $createdAt = '';
}
