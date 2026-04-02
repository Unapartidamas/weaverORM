<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine\Attribute;

use Weaver\ORM\Mapping\Attribute\Superclass;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class MappedSuperclass extends Superclass {}
