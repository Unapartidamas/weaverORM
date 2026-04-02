<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class CheckViolation extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Check constraint violation: a value does not satisfy the column constraint.',
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
