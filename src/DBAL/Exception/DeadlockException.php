<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class DeadlockException extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Deadlock detected: two or more transactions are waiting for each other. Retry the operation.',
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
