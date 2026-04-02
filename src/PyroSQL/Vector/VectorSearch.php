<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL\Vector;

final class VectorSearch
{

    private function __construct() {}

    public static function nearestNeighbors(
        string $column,
        array $vector,
        int $k = 10,
        string $distanceOp = 'cosine',
    ): array {
        $operator    = self::distanceOperator($distanceOp);
        $literal     = self::formatVector($vector);
        $quotedCol   = '"' . str_replace('"', '""', $column) . '"';
        $orderBy     = "{$quotedCol} {$operator} '{$literal}'";
        $distanceCol = "({$quotedCol} {$operator} '{$literal}') AS _distance";

        return [
            'orderBy'        => $orderBy,
            'limit'          => $k,
            'distanceColumn' => $distanceCol,
        ];
    }

    public static function formatVector(array $vector): string
    {
        $parts = array_map(
            static fn (mixed $v): string => (string) (is_numeric($v) ? (float) $v : 0.0),
            $vector,
        );

        return '[' . implode(',', $parts) . ']';
    }

    public static function distanceOperator(string $op): string
    {
        return match ($op) {
            'cosine' => '<=>',
            'l2'     => '<->',
            'dot'    => '<#>',
            default  => throw new \InvalidArgumentException(
                "Unknown distance operator '{$op}'. Supported: 'cosine', 'l2', 'dot'."
            ),
        };
    }
}
