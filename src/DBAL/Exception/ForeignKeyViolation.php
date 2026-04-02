<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class ForeignKeyViolation extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Foreign key constraint violation: a referenced record does not exist or is still referenced.',
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
