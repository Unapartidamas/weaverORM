<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Embeddable;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embeddable;

#[Embeddable]
class Coordinates
{
    #[Column(type: 'float')]
    public float $lat = 0.0;
    #[Column(type: 'float')]
    public float $lng = 0.0;
}
