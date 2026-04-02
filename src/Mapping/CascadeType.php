<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

enum CascadeType
{

    case Persist;

    case Remove;

    case Detach;

    case All;
}
