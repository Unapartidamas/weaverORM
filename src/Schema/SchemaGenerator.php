<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema;

use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\InheritanceMapping;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationType;

final readonly class SchemaGenerator
{
    public function __construct(
        private MapperRegistry $registry,
        private Platform $platform,
    ) {}

    public function generateSql(): array
    {
        $statements = [];

        foreach ($this->registry->all() as $mapper) {
            if ($mapper instanceof AbstractEntityMapper) {
                $im = $mapper->getInheritanceMapping();
                if ($im !== null && $im->type === InheritanceMapping::JOINED) {
                    $statements = array_merge($statements, $this->buildJoinedInheritanceSql($mapper, $im));
                } else {
                    $statements[] = $this->buildCreateTableSql($mapper);
                    $statements = array_merge($statements, $this->buildIndexSql($mapper));
                }
            }
        }

        return $statements;
    }

    public function generateForMapper(AbstractEntityMapper $mapper): string
    {
        return $this->buildCreateTableSql($mapper);
    }

    private function buildJoinedInheritanceSql(AbstractEntityMapper $mapper, InheritanceMapping $im): array
    {
        $statements = [];
        $statements[] = $this->buildCreateTableSql($mapper);
        $statements = array_merge($statements, $this->buildIndexSql($mapper));

        foreach ($im->childTables as $childClass => $childTableName) {
            $childMapper = null;
            foreach ($this->registry->all() as $m) {
                if ($m instanceof AbstractEntityMapper && $m->getEntityClass() === $childClass) {
                    $childMapper = $m;
                    break;
                }
            }

            if ($childMapper !== null) {
                $statements[] = $this->buildCreateTableSql($childMapper);
                $statements = array_merge($statements, $this->buildIndexSql($childMapper));
            }
        }

        return $statements;
    }

    private function buildCreateTableSql(AbstractEntityMapper $mapper): string
    {
        $table = $mapper->getTableName();
        $columnDefs = [];
        $primaryColumns = [];

        foreach ($mapper->getColumns() as $col) {
            if ($col->isVirtual()) {
                continue;
            }

            $colSql = $this->platform->quoteIdentifier($col->getColumn()) . ' ' . $this->mapType($col->getType(), $col);

            if ($col->isAutoIncrement()) {
                $platformName = $this->platform->getName();
                if ($platformName === 'sqlite') {
                    $colSql = $this->platform->quoteIdentifier($col->getColumn()) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                    $columnDefs[] = $colSql;
                    continue;
                } elseif ($platformName === 'postgresql' || $platformName === 'pyrosql') {
                    $colSql = $this->platform->quoteIdentifier($col->getColumn()) . ' SERIAL';
                } else {
                    $colSql .= ' AUTO_INCREMENT';
                }
            }

            if (!$col->isNullable()) {
                $colSql .= ' NOT NULL';
            }

            if ($col->getDefault() !== null) {
                $colSql .= ' DEFAULT ' . $this->quoteDefault($col->getDefault());
            }

            if ($col->isPrimary()) {
                $primaryColumns[] = $col->getColumn();
            }

            $columnDefs[] = $colSql;
        }

        if ($primaryColumns !== []) {
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', array_map([$this->platform, 'quoteIdentifier'], $primaryColumns)) . ')';
        }

        foreach ($mapper->getRelations() as $relation) {
            if ($relation->getType() !== RelationType::BelongsTo) {
                continue;
            }

            $relatedMapper = $this->registry->get($relation->getRelatedEntity());
            $localFk = $relation->getForeignKey() ?? $relatedMapper->getPrimaryKey();
            $relatedPk = $relation->getOwnerKey() ?? $relatedMapper->getPrimaryKey();
            $relatedTable = $relatedMapper->getTableName();

            $columnDefs[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s)',
                $this->platform->quoteIdentifier($localFk),
                $this->platform->quoteIdentifier($relatedTable),
                $this->platform->quoteIdentifier($relatedPk),
            );
        }

        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $this->platform->quoteIdentifier($table),
            implode(', ', $columnDefs),
        );
    }

    private function buildIndexSql(AbstractEntityMapper $mapper): array
    {
        $statements = [];
        $table = $mapper->getTableName();

        foreach ($mapper->getIndexes() as $index) {
            $indexColumns = $index->getColumns();
            if ($indexColumns === []) {
                continue;
            }

            $cols = implode(', ', array_map([$this->platform, 'quoteIdentifier'], $indexColumns));
            $indexName = $index->getName() ?? ('idx_' . $table . '_' . implode('_', $indexColumns));

            if ($index->isUnique()) {
                $statements[] = sprintf(
                    'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
                    $this->platform->quoteIdentifier($indexName),
                    $this->platform->quoteIdentifier($table),
                    $cols,
                );
            } else {
                $sql = sprintf(
                    'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                    $this->platform->quoteIdentifier($indexName),
                    $this->platform->quoteIdentifier($table),
                    $cols,
                );

                if ($index->getWhere() !== null) {
                    $sql .= ' WHERE ' . $index->getWhere();
                }

                $statements[] = $sql;
            }
        }

        return $statements;
    }

    private function mapType(string $type, $col): string
    {
        $length = $col->getLength();

        return match ($type) {
            'integer', 'int' => 'INTEGER',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'string' => 'VARCHAR(' . ($length ?? 255) . ')',
            'text' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'datetime', 'datetime_immutable' => 'TIMESTAMP',
            'date', 'date_immutable' => 'DATE',
            'time', 'time_immutable' => 'TIME',
            'float', 'double' => 'DOUBLE PRECISION',
            'decimal' => sprintf('DECIMAL(%d,%d)', $col->getPrecision() ?? 10, $col->getScale() ?? 2),
            'json' => 'JSON',
            'blob', 'binary' => 'BLOB',
            'uuid', 'guid' => 'CHAR(36)',
            'ulid' => 'CHAR(26)',
            'encrypted_string' => 'TEXT',
            'money_cents' => 'INTEGER',
            'ip_address' => 'VARCHAR(45)',
            'phone' => 'VARCHAR(30)',
            'enum_string' => 'VARCHAR(100)',
            default => 'TEXT',
        };
    }

    private function quoteDefault(mixed $default): string
    {
        if (is_bool($default)) {
            return $default ? 'TRUE' : 'FALSE';
        }
        if (is_int($default) || is_float($default)) {
            return (string) $default;
        }
        return "'" . str_replace("'", "''", (string) $default) . "'";
    }
}
