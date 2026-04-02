<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class LockWaitTimeoutException extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Lock wait timeout: another transaction holds a lock on the requested resource.',
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
