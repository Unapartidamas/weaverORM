<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDOStatement;

class Result
{
    public function __construct(private readonly ?PDOStatement $stmt = null)
    {
    }

    public function fetchAssociative(): array|false
    {
        return $this->stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function fetchNumeric(): array|false
    {
        return $this->stmt->fetch(\PDO::FETCH_NUM);
    }

    public function fetchOne(): mixed
    {
        return $this->stmt->fetchColumn();
    }

    public function fetchAllAssociative(): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function fetchAllNumeric(): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_NUM);
    }

    public function fetchFirstColumn(): array
    {
        return $this->stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }

    public function columnCount(): int
    {
        return $this->stmt->columnCount();
    }

    public function free(): void
    {
        $this->stmt->closeCursor();
    }
}
