<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class SyntaxError extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'SQL syntax error: ' . $e->getMessage(),
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
