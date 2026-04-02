<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Embeddable;

use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Embeddable;

#[Embeddable]
class Money
{
    public function __construct(
        #[Column(type: 'integer')]
        public int $amount = 0,
        #[Column(type: 'string', length: 3)]
        public string $currency = 'EUR',
    ) {}
}
