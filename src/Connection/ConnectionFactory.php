<?php

declare(strict_types=1);

namespace Weaver\ORM\Connection;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory as DbalConnectionFactory;

final class ConnectionFactory
{
    public function createConnection(array $params): Connection
    {
        return DbalConnectionFactory::create($params);
    }
}
