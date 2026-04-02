<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine\Attribute;

use Weaver\ORM\Mapping\Attribute\TypeColumn;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DiscriminatorColumn extends TypeColumn {}
