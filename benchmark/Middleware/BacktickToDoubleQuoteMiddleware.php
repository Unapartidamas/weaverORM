<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware that rewrites backtick-quoted identifiers to double-quoted
 * identifiers so that Weaver's UnitOfWork (which emits MySQL-style backtick
 * quoting) can run against PostgreSQL.
 *
 * Backticks are not valid SQL in PostgreSQL; double-quotes are the ANSI
 * standard and are used by PostgreSQL for quoted identifiers.
 */
final class BacktickToDoubleQuoteMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new BacktickDriver($driver);
    }
}
