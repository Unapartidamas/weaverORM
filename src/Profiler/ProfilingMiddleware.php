<?php

declare(strict_types=1);

namespace Weaver\ORM\Profiler;

use Weaver\ORM\DBAL\Connection;

final class ProfilingMiddleware
{
    public function __construct(private readonly QueryProfiler $profiler) {}

    public function wrap(Connection $connection): ProfilingConnection
    {
        return new ProfilingConnection($connection, $this->profiler);
    }
}
