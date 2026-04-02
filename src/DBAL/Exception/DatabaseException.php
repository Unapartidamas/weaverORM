<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL\Exception;

class DatabaseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sql,
        public readonly string $sqlState,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
