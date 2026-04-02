<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine\Attribute;

use Weaver\ORM\Mapping\Attribute\Inheritance;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class InheritanceType extends Inheritance {}
