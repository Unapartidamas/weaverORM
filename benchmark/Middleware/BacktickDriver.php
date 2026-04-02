<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Middleware;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Driver wrapper that returns a {@see BacktickConnection} instead of the
 * underlying driver connection, so that all SQL is rewritten before it reaches
 * the database.
 */
final class BacktickDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new BacktickConnection(parent::connect($params));
    }
}
