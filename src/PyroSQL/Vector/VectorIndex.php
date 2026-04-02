<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Vector;

final readonly class VectorIndex
{
    private function __construct(
        private string $type,
        private string $column,
        private string $distanceOp,

        private array $options
    )
    {
    }

    public static function hnsw(
        string $column,
        string $distanceOp = 'cosine',
        int $m = 16,
        int $efConstruction = 64,
    ): self {
        return new self(
            type:       'hnsw',
            column:     $column,
            distanceOp: $distanceOp,
            options:    ['m' => $m, 'ef_construction' => $efConstruction],
        );
    }

    public static function ivfflat(
        string $column,
        string $distanceOp = 'cosine',
        int $lists = 100,
    ): self {
        return new self(
            type:       'ivfflat',
            column:     $column,
            distanceOp: $distanceOp,
            options:    ['lists' => $lists],
        );
    }

    public function toSQL(string $tableName, ?string $indexName = null): string
    {
        $opClass   = $this->opClass();
        $indexName ??= sprintf('idx_%s_%s_%s', $tableName, $this->column, $this->type);

        $withParts = [];

        foreach ($this->options as $key => $value) {
            $strValue = is_scalar($value) ? (string) $value : '';
            $withParts[] = "{$key}={$strValue}";
        }

        $withClause = $withParts !== [] ? ' WITH (' . implode(', ', $withParts) . ')' : '';

        return sprintf(
            'CREATE INDEX %s ON %s USING %s (%s %s)%s',
            $indexName,
            $tableName,
            $this->type,
            $this->column,
            $opClass,
            $withClause,
        );
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getDistanceOp(): string
    {
        return $this->distanceOp;
    }

    private function opClass(): string
    {
        return match ($this->distanceOp) {
            'cosine' => 'vector_cosine_ops',
            'l2'     => 'vector_l2_ops',
            'dot'    => 'vector_ip_ops',
            default  => 'vector_cosine_ops',
        };
    }
}
