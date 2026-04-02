<?php

declare(strict_types=1);

namespace Weaver\ORM\Hydration;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\DBAL\Type\Type;
use Weaver\ORM\Mapping\ColumnDefinition;

final readonly class PivotHydrator
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function extractPivot(array $row, string $pivotAlias, array $pivotColumns): array
    {
        $prefixLength = strlen($pivotAlias);
        $rawPivot     = [];

        foreach ($row as $key => $value) {
            if (str_starts_with($key, $pivotAlias)) {
                $originalColumn       = substr($key, $prefixLength);
                $rawPivot[$originalColumn] = $value;
            }
        }

        if ($rawPivot === []) {
            return [];
        }

        return $this->hydratePivot(
            $rawPivot,
            $pivotColumns,
            $this->connection->getDatabasePlatform(),
        );
    }

    public function hydratePivot(array $row, array $pivotColumns, Platform $platform): array
    {

        $definitionMap = [];

        foreach ($pivotColumns as $colDef) {
            $definitionMap[$colDef->getColumn()] = $colDef;
        }

        $result = [];

        foreach ($row as $column => $raw) {
            if (!isset($definitionMap[$column])) {

                $result[$column] = $raw;
                continue;
            }

            $colDef = $definitionMap[$column];

            if ($raw === null) {
                $result[$column] = null;
                continue;
            }

            $phpValue = Type::getType($colDef->getType())
                ->convertToPHPValue($raw, $platform);

            $enumClass = $colDef->getEnumClass();

            if ($enumClass !== null && $phpValue !== null) {

                $scalarValue = is_int($phpValue) ? $phpValue : (is_scalar($phpValue) ? (string) $phpValue : '');
                $phpValue = $enumClass::from($scalarValue);
            }

            $result[$column] = $phpValue;
        }

        return $result;
    }
}
