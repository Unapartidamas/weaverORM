<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine\Attribute;

use Weaver\ORM\Mapping\Attribute\BeforeDelete;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class PreRemove extends BeforeDelete {}
