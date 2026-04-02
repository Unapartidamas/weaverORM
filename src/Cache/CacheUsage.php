<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

enum CacheUsage: string
{
    case READ_WRITE = 'READ_WRITE';
    case READ_ONLY = 'READ_ONLY';
    case NONSTRICT_READ_WRITE = 'NONSTRICT_READ_WRITE';
}
