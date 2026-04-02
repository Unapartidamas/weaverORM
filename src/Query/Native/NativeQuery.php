<?php

declare(strict_types=1);

namespace Weaver\ORM\Query\Native;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Mapping\MapperRegistry;

final class NativeQuery
{
    private string $sql = '';

    private array $params = [];

    private array $types = [];
    private ?ResultSetMapping $rsm = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly MapperRegistry $registry,
    ) {}

    public function setSql(string $sql): static { $this->sql = $sql; return $this; }

    public function setParameters(array $params, array $types = []): static
    {
        $this->params = $params;
        $this->types  = $types;
        return $this;
    }

    public function setResultSetMapping(ResultSetMapping $rsm): static { $this->rsm = $rsm; return $this; }

    public function execute(): NativeQueryResult
    {
        if ($this->rsm === null) {
            throw new \LogicException('Call setResultSetMapping() before execute().');
        }

        $rows = $this->connection->fetchAllAssociative($this->sql, $this->params, $this->types);

        return new NativeQueryResult($this->hydrateRows($rows, $this->rsm));
    }

    private function hydrateRows(array $rows, ResultSetMapping $rsm): array
    {
        $rootAlias  = $rsm->getRootAlias();
        $rootClass  = $rsm->getRootEntityClass();

        $results = [];

        foreach ($rows as $row) {

            $rootData = [];
            foreach ($rsm->getFieldMappings($rootAlias) as $column => $property) {
                if (array_key_exists($column, $row)) {
                    $rootData[$column] = $row[$column];
                }
            }

            $entity = $this->hydrateEntity($rootClass, $rootData, $rsm->getFieldMappings($rootAlias));

            foreach ($rsm->getEntities() as $alias => $entityClass) {
                if ($alias === $rootAlias) {
                    continue;
                }
                if (!$rsm->isJoinedAlias($alias)) {
                    continue;
                }

                $parentProperty = $rsm->getJoinedEntityParentProperties()[$alias];
                $joinedData     = [];
                foreach ($rsm->getFieldMappings($alias) as $column => $property) {
                    if (array_key_exists($column, $row)) {
                        $joinedData[$column] = $row[$column];
                    }
                }

                if (array_filter($joinedData, fn($v) => $v !== null) === []) {
                    continue;
                }

                $joined                 = $this->hydrateEntity($entityClass, $joinedData, $rsm->getFieldMappings($alias));
                $entity->$parentProperty = $joined;
            }

            $results[] = $entity;
        }

        return $results;
    }

    private function hydrateEntity(string $entityClass, array $data, array $fieldMappings): object
    {
        $entity = new $entityClass();
        foreach ($fieldMappings as $column => $property) {
            if (array_key_exists($column, $data) && property_exists($entity, $property)) {
                $entity->$property = $data[$column];
            }
        }
        return $entity;
    }
}
