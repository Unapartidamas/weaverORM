<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class ValueTooLongException extends DatabaseException
{
    public static function fromPdoException(\PDOException $e, string $sql): self
    {
        return new self(
            'Value too long for column: ' . $e->getMessage(),
            $sql,
            $e->getCode() ?: 'HY000',
            $e,
        );
    }
}
