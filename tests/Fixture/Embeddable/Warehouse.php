<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Embeddable;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embedded;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;

#[Entity(table: 'warehouses')]
class Warehouse
{
    #[Id]
    public int $id = 0;
    #[Column]
    public string $name = '';
    #[Embedded(class: Address::class, prefix: 'addr_')]
    public ?Address $address = null;
}
