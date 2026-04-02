<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Embeddable;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embeddable;
use Weaver\ORM\Mapping\Attribute\Embedded;

#[Embeddable]
class Address
{
    #[Column(type: 'string')]
    public string $street = '';
    #[Column(type: 'string')]
    public string $city = '';
    #[Embedded(class: Coordinates::class, prefix: 'coord_')]
    public ?Coordinates $coordinates = null;
}
