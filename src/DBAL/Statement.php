<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

use PDO;
use PDOStatement;

class Statement
{
    public function __construct(private readonly ?PDOStatement $stmt = null)
    {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType|int|string $type = PDO::PARAM_STR): void
    {
        $pdoType = match (true) {
            $type instanceof ParameterType => $type->value,
            is_int($type) => $type,
            default => self::resolvePdoType($type),
        };

        $this->stmt->bindValue($param, $value, $pdoType);
    }

    public function execute(array $params = []): Result
    {
        $this->stmt->execute($params ?: null);

        return new Result($this->stmt);
    }

    public function executeStatement(array $params = []): int
    {
        $this->stmt->execute($params ?: null);

        return $this->stmt->rowCount();
    }

    private static function resolvePdoType(string $type): int
    {
        return match ($type) {
            'integer', 'smallint', 'bigint', 'int' => PDO::PARAM_INT,
            'boolean', 'bool' => PDO::PARAM_BOOL,
            'binary', 'blob' => PDO::PARAM_LOB,
            'null' => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
