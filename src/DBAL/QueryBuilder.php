<?php

declare(strict_types=1);

namespace Weaver\ORM\DBAL;

final class QueryBuilder
{
    private string $type = 'SELECT';
    private array $selects = ['*'];
    private ?string $from = null;
    private ?string $fromAlias = null;
    private array $wheres = [];
    private array $params = [];
    private array $paramTypes = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private array $having = [];
    private ?int $limit = null;
    private int $offset = 0;
    private array $joins = [];
    private bool $distinct = false;
    private array $sets = [];
    private array $values = [];
    private ?Connection $connection = null;

    public function setConnection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function executeQuery(): Result
    {
        if ($this->connection === null) {
            throw new \LogicException('Cannot executeQuery without a connection. Use setConnection() or Connection::createQueryBuilder().');
        }

        $sql = $this->getSQL();
        $params = $this->params;
        $types = $this->paramTypes;

        $expandedSql = $sql;
        $expandedParams = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $i => $v) {
                    $paramKey = $key . '_' . $i;
                    $placeholders[] = ':' . $paramKey;
                    $expandedParams[$paramKey] = $v;
                }
                $expandedSql = str_replace(':' . $key, implode(', ', $placeholders), $expandedSql);
            } else {
                $expandedParams[$key] = $value;
            }
        }

        $stmt = $this->connection->getNativeConnection()->prepare($expandedSql);

        foreach ($expandedParams as $key => $value) {
            $pdoType = \PDO::PARAM_STR;
            if (is_int($value)) {
                $pdoType = \PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $pdoType = \PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $pdoType = \PDO::PARAM_NULL;
            }
            $stmt->bindValue($key, $value, $pdoType);
        }

        $stmt->execute();

        return new Result($stmt);
    }

    public function executeStatement(): int
    {
        if ($this->connection === null) {
            throw new \LogicException('Cannot executeStatement without a connection.');
        }

        $sql = $this->getSQL();
        $params = $this->params;

        $expandedSql = $sql;
        $expandedParams = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $i => $v) {
                    $paramKey = $key . '_' . $i;
                    $placeholders[] = ':' . $paramKey;
                    $expandedParams[$paramKey] = $v;
                }
                $expandedSql = str_replace(':' . $key, implode(', ', $placeholders), $expandedSql);
            } else {
                $expandedParams[$key] = $value;
            }
        }

        $stmt = $this->connection->getNativeConnection()->prepare($expandedSql);

        foreach ($expandedParams as $key => $value) {
            $pdoType = \PDO::PARAM_STR;
            if (is_int($value)) {
                $pdoType = \PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $pdoType = \PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $pdoType = \PDO::PARAM_NULL;
            }
            $stmt->bindValue($key, $value, $pdoType);
        }

        $stmt->execute();

        return $stmt->rowCount();
    }

    public function select(string ...$columns): self
    {
        $this->selects = $columns;

        return $this;
    }

    public function addSelect(string ...$columns): self
    {
        if ($this->selects === ['*']) {
            $this->selects = [];
        }

        foreach ($columns as $column) {
            $this->selects[] = $column;
        }

        return $this;
    }

    public function selectRaw(string $expression): self
    {
        if ($this->selects === ['*']) {
            $this->selects = [];
        }

        $this->selects[] = $expression;

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->from = $table;
        $this->fromAlias = $alias;

        return $this;
    }

    public function where(string $expression, array $params = []): self
    {
        $this->wheres[] = ['AND', $expression];
        $this->mergeParams($params);

        return $this;
    }

    public function andWhere(string $expression, array $params = []): self
    {
        return $this->where($expression, $params);
    }

    public function orWhere(string $expression, array $params = []): self
    {
        $this->wheres[] = ['OR', $expression];
        $this->mergeParams($params);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy = [[$column, strtoupper($direction)]];

        return $this;
    }

    public function addOrderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [$column, strtoupper($direction)];

        return $this;
    }

    public function addOrderByRaw(string $expression): self
    {
        $this->orderBy[] = [$expression, ''];

        return $this;
    }

    public function setMaxResults(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function setFirstResult(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = $columns;

        return $this;
    }

    public function having(string $expression, array $params = []): self
    {
        $this->having[] = ['AND', $expression];
        $this->mergeParams($params);

        return $this;
    }

    public function andHaving(string $expression, array $params = []): self
    {
        return $this->having($expression, $params);
    }

    public function orHaving(string $expression, array $params = []): self
    {
        $this->having[] = ['OR', $expression];
        $this->mergeParams($params);

        return $this;
    }

    public function join(string $fromAliasOrTable, string $tableOrAlias, string $aliasOrCondition, ?string $condition = null): self
    {
        if ($condition !== null) {
            $this->joins[] = ['INNER', $tableOrAlias, $aliasOrCondition, $condition];
        } else {
            $this->joins[] = ['INNER', $fromAliasOrTable, $tableOrAlias, $aliasOrCondition];
        }

        return $this;
    }

    public function leftJoin(string $fromAliasOrTable, string $tableOrAlias, string $aliasOrCondition, ?string $condition = null): self
    {
        if ($condition !== null) {
            $this->joins[] = ['LEFT', $tableOrAlias, $aliasOrCondition, $condition];
        } else {
            $this->joins[] = ['LEFT', $fromAliasOrTable, $tableOrAlias, $aliasOrCondition];
        }

        return $this;
    }

    public function rightJoin(string $fromAliasOrTable, string $tableOrAlias, string $aliasOrCondition, ?string $condition = null): self
    {
        if ($condition !== null) {
            $this->joins[] = ['RIGHT', $tableOrAlias, $aliasOrCondition, $condition];
        } else {
            $this->joins[] = ['RIGHT', $fromAliasOrTable, $tableOrAlias, $aliasOrCondition];
        }

        return $this;
    }

    public function insert(string $table): self
    {
        $this->type = 'INSERT';
        $this->from = $table;

        return $this;
    }

    public function update(string $table, ?string $alias = null): self
    {
        $this->type = 'UPDATE';
        $this->from = $table;
        $this->fromAlias = $alias;

        return $this;
    }

    public function delete(string $table, ?string $alias = null): self
    {
        $this->type = 'DELETE';
        $this->from = $table;
        $this->fromAlias = $alias;

        return $this;
    }

    public function set(string $column, string $value): self
    {
        $this->sets[] = [$column, $value];

        return $this;
    }

    public function setValue(string $column, string $value): self
    {
        $this->values[] = [$column, $value];

        return $this;
    }

    public function getSQL(): string
    {
        return match ($this->type) {
            'SELECT' => $this->buildSelect(),
            'INSERT' => $this->buildInsert(),
            'UPDATE' => $this->buildUpdate(),
            'DELETE' => $this->buildDelete(),
        };
    }

    public function setParameter(string $key, mixed $value, ParameterType|ArrayParameterType|string|null $type = null): self
    {
        $this->params[$key] = $value;
        if ($type !== null) {
            $this->paramTypes[$key] = $type;
        }

        return $this;
    }

    public function getParameters(): array
    {
        return $this->params;
    }

    public function getParameterTypes(): array
    {
        return $this->paramTypes;
    }

    public function getMaxResults(): ?int
    {
        return $this->limit;
    }

    private function buildSelect(): string
    {
        $sql = 'SELECT';

        if ($this->distinct) {
            $sql .= ' DISTINCT';
        }

        $sql .= ' ' . implode(', ', $this->selects);
        $sql .= ' FROM ' . $this->from;

        if ($this->fromAlias !== null) {
            $sql .= ' ' . $this->fromAlias;
        }

        $sql .= $this->buildJoins();
        $sql .= $this->buildWhere();
        $sql .= $this->buildGroupBy();
        $sql .= $this->buildHaving();
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimitOffset();

        return $sql;
    }

    private function buildInsert(): string
    {
        $columns = [];
        $vals = [];

        foreach ($this->values as [$column, $value]) {
            $columns[] = $column;
            $vals[] = $value;
        }

        return 'INSERT INTO ' . $this->from
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $vals) . ')';
    }

    private function buildUpdate(): string
    {
        $sql = 'UPDATE ' . $this->from;

        if ($this->fromAlias !== null) {
            $sql .= ' ' . $this->fromAlias;
        }

        $setParts = [];

        foreach ($this->sets as [$column, $value]) {
            $setParts[] = $column . ' = ' . $value;
        }

        $sql .= ' SET ' . implode(', ', $setParts);
        $sql .= $this->buildWhere();

        return $sql;
    }

    private function buildDelete(): string
    {
        $sql = 'DELETE FROM ' . $this->from;

        if ($this->fromAlias !== null) {
            $sql .= ' ' . $this->fromAlias;
        }

        $sql .= $this->buildWhere();

        return $sql;
    }

    private function buildJoins(): string
    {
        if ($this->joins === []) {
            return '';
        }

        $parts = [];

        foreach ($this->joins as [$type, $table, $alias, $condition]) {
            $parts[] = ' ' . $type . ' JOIN ' . $table . ' ' . $alias . ' ON ' . $condition;
        }

        return implode('', $parts);
    }

    private function buildWhere(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $sql = ' WHERE ';
        $first = true;

        foreach ($this->wheres as [$connector, $expression]) {
            if ($first) {
                $sql .= $expression;
                $first = false;
            } else {
                $sql .= ' ' . $connector . ' ' . $expression;
            }
        }

        return $sql;
    }

    private function buildGroupBy(): string
    {
        if ($this->groupBy === []) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }

    private function buildHaving(): string
    {
        if ($this->having === []) {
            return '';
        }

        $sql = ' HAVING ';
        $first = true;

        foreach ($this->having as [$connector, $expression]) {
            if ($first) {
                $sql .= $expression;
                $first = false;
            } else {
                $sql .= ' ' . $connector . ' ' . $expression;
            }
        }

        return $sql;
    }

    private function buildOrderBy(): string
    {
        if ($this->orderBy === []) {
            return '';
        }

        $parts = [];

        foreach ($this->orderBy as [$column, $direction]) {
            $parts[] = $direction !== '' ? $column . ' ' . $direction : $column;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function buildLimitOffset(): string
    {
        $sql = '';

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset > 0) {
            $sql .= ' LIMIT -1';
        }

        if ($this->offset > 0) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    private function mergeParams(array $params): void
    {
        foreach ($params as $param) {
            $this->params[] = $param;
        }
    }
}
