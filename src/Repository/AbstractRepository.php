<?php

declare(strict_types=1);

namespace Weaver\ORM\Repository;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Contract\RepositoryInterface;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Pagination\Page;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Query\Filter\QueryFilterRegistry;
use Weaver\ORM\Relation\RelationLoader;

abstract class AbstractRepository implements RepositoryInterface
{

    protected string $entityClass;

    protected array $globalScopes = [];

    public function __construct(
        protected readonly Connection $connection,
        protected readonly MapperRegistry $registry,
        protected readonly EntityHydrator $hydrator,
        protected readonly RelationLoader $relationLoader,
        protected readonly UnitOfWork $unitOfWork,
        protected readonly LifecycleEventDispatcher $eventDispatcher,
        protected readonly ?QueryFilterRegistry $queryFilterRegistry = null,
    ) {}

    public function find(mixed $id): ?object
    {
        $mapper   = $this->getMapper();
        $table    = $mapper->getTableName();
        $pkColumn = $mapper->getPrimaryKey();
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1',
            $table,
            $pkColumn,
        );

        $row = $this->connection->fetchAssociative($sql, [$id]);

        if ($row === false) {
            return null;
        }

        $entity = $this->hydrator->hydrate($this->entityClass, $row);
        $this->unitOfWork->track($entity, $this->entityClass);

        return $entity;
    }

    public function findOrFail(mixed $id): object
    {
        $entity = $this->find($id);

        if ($entity === null) {
            throw EntityNotFoundException::forId($this->entityClass, $id);
        }

        return $entity;
    }

    public function findBy(
        array $criteria,
        array $with = [],
        ?int $limit = null,
        ?string $orderBy = null,
        string $direction = 'ASC',
    ): EntityCollection {
        $qb = $this->query();

        foreach ($criteria as $column => $value) {
            $qb->where($column, $value);
        }

        if ($orderBy !== null) {
            $qb->orderBy($orderBy, $direction);
        }

        if ($limit !== null) {
            $qb->limit($limit);
        }

        return $qb->get($with);
    }

    public function findOneBy(array $criteria, array $with = []): ?object
    {
        $collection = $this->findBy($criteria, $with, limit: 1);
        $result = $collection->first();

        return is_object($result) ? $result : null;
    }

    public function save(object $entity): void
    {
        $this->unitOfWork->add($entity);
    }

    public function delete(object $entity): void
    {
        $this->unitOfWork->delete($entity);
    }

    public function push(): void
    {
        $this->unitOfWork->push();
    }

    public function query(string $alias = 'e'): EntityQueryBuilder
    {
        $qb = new EntityQueryBuilder(
            connection:      $this->connection,
            entityClass:     $this->entityClass,
            mapper:          $this->getMapper(),
            hydrator:        $this->hydrator,
            relationLoader:  $this->relationLoader,
            alias:           $alias,
            filterRegistry:  $this->queryFilterRegistry,
        );

        $this->applyGlobalScopes($qb);

        return $qb;
    }

    public function hydrateRaw(string $sql, array $params = [], array $types = []): EntityCollection
    {
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return $this->hydrator->hydrateMany($this->entityClass, $rows);
    }

    public function count(array $criteria = []): int
    {
        $mapper   = $this->getMapper();
        $table    = $mapper->getTableName();

        $sql    = sprintf('SELECT COUNT(*) FROM `%s`', $table);
        $params = [];

        if ($criteria !== []) {
            $conditions = [];

            foreach ($criteria as $column => $value) {
                $conditions[] = sprintf('`%s` = ?', $column);
                $params[] = $value;
            }

            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $raw = $this->connection->fetchOne($sql, $params);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public function bulkInsert(array $entities): void
    {
        if ($entities === []) {
            return;
        }

        $mapper    = $this->getMapper();
        $table     = $mapper->getTableName();
        $colDefs   = $mapper->getPersistableColumns();

        $typeMap = [];
        foreach ($colDefs as $col) {
            $typeMap[$col->getColumn()] = $col->getType();
        }

        foreach ($entities as $entity) {
            $data = $this->hydrator->extract($entity, $this->entityClass);

            if ($data === []) {
                continue;
            }

            $columns      = array_keys($data);
            $values       = array_values($data);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnList   = implode(', ', array_map(
                static fn (string $c): string => '`' . $c . '`',
                $columns,
            ));

            $rowTypes = [];
            foreach ($columns as $colName) {
                $rowTypes[] = $typeMap[$colName] ?? 'string';
            }

            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                $table,
                $columnList,
                $placeholders,
            );

            $this->connection->executeStatement($sql, $values, $rowTypes);
        }
    }

    public function stream(array $criteria = []): \Generator
    {
        $qb = $this->query();

        foreach ($criteria as $column => $value) {
            $qb->where($column, $value);
        }

        return $qb->cursor();
    }

    public function paginate(int $page = 1, int $perPage = 15, array $with = []): Page
    {
        return $this->query()->paginate($page, $perPage, $with);
    }

    public function addGlobalScope(string $name, callable $scope): void
    {
        $this->globalScopes[$name] = $scope;
    }

    public function withoutScope(string $name): static
    {
        $clone = clone $this;
        unset($clone->globalScopes[$name]);

        return $clone;
    }

    protected function applyGlobalScopes(EntityQueryBuilder $qb): void
    {
        foreach ($this->globalScopes as $scope) {
            ($scope)($qb);
        }
    }

    protected function getMapper(): AbstractEntityMapper
    {

        return $this->registry->get($this->entityClass);
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }
}
