<?php

declare(strict_types=1);

namespace Weaver\ORM\Connection;

use Weaver\ORM\DBAL\Connection;

final class ReadWriteConnection
{
    public function __construct(
        private readonly Connection $write,
        private readonly Connection $read,
    ) {}

    public function getWriteConnection(): Connection
    {
        return $this->write;
    }

    public function getReadConnection(): Connection
    {
        return $this->read;
    }
}
