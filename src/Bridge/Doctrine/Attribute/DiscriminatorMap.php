<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine\Attribute;

use Weaver\ORM\Mapping\Attribute\TypeMap;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DiscriminatorMap extends TypeMap {}
