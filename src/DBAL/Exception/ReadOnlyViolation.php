<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class ReadOnlyViolation extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Cannot write: the database or transaction is in read-only mode.',
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
