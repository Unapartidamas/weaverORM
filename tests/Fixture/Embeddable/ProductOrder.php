<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Embeddable;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embedded;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;

#[Entity(table: 'product_orders')]
class ProductOrder
{
    #[Id]
    public int $id = 0;
    #[Column]
    public string $name = '';
    #[Embedded(class: Money::class, prefix: 'price_')]
    public ?Money $price = null;
    #[Embedded(class: Money::class, prefix: 'tax_')]
    public ?Money $tax = null;
}
