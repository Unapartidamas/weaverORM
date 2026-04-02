<?php

declare(strict_types=1);

namespace Weaver\ORM\Manager;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\Connection\ReadWriteConnection;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Profiler\QueryProfiler;
use Weaver\ORM\Repository\EntityRepository;
use Weaver\ORM\Repository\RepositoryFactory;

final class EntityWorkspace
{
    private readonly RepositoryFactory $repositoryFactory;
    private ?QueryProfiler $profiler = null;

    public function __construct(
        private readonly string $name,
        private readonly Connection $connection,
        private readonly MapperRegistry $mapperRegistry,
        private readonly UnitOfWork $unitOfWork,
        private readonly ?ReadWriteConnection $readWrite = null,
    ) {
        $this->repositoryFactory = new RepositoryFactory();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getReadConnection(): Connection
    {
        return $this->readWrite !== null
            ? $this->readWrite->getReadConnection()
            : $this->connection;
    }

    public function getWriteConnection(): Connection
    {
        return $this->readWrite !== null
            ? $this->readWrite->getWriteConnection()
            : $this->connection;
    }

    public function getMapperRegistry(): MapperRegistry
    {
        return $this->mapperRegistry;
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    public function getProfiler(): ?QueryProfiler
    {
        return $this->profiler;
    }

    public function setProfiler(QueryProfiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    public function add(object $entity): void
    {
        $this->unitOfWork->add($entity);
    }

    public function delete(object $entity): void
    {
        $this->unitOfWork->delete($entity);
    }

    public function reset(): void
    {
        $this->unitOfWork->reset();
    }

    public function push(?object $entity = null): void
    {
        $this->unitOfWork->push($entity);
    }

    public function upsert(object $entity): void
    {
        $this->unitOfWork->upsert($entity);
    }

    public function reload(object $entity): void
    {
        $this->unitOfWork->reload($entity);
    }

    public function untrack(object $entity): void
    {
        $this->unitOfWork->untrack($entity);
    }

    public function merge(object $entity): object
    {
        return $this->unitOfWork->merge($entity);
    }

    public function isTracked(object $entity): bool
    {
        return $this->unitOfWork->isTracked($entity);
    }

    public function isDirty(object $entity): bool
    {
        return $this->unitOfWork->isEntityDirty($entity);
    }

    public function isNew(object $entity): bool
    {
        return $this->unitOfWork->isEntityNew($entity);
    }

    public function isManaged(object $entity): bool
    {
        return $this->unitOfWork->isEntityManaged($entity);
    }

    public function isDeleted(object $entity): bool
    {
        return $this->unitOfWork->isEntityDeleted($entity);
    }

    public function getChanges(object $entity): array
    {
        return $this->unitOfWork->computeChangeSet($entity);
    }

    public function getOriginalValue(object $entity, string $property): mixed
    {
        $changes = $this->unitOfWork->computeChangeSet($entity);

        if (array_key_exists($property, $changes)) {
            return $changes[$property]['old'];
        }

        $mapper = $this->mapperRegistry->get($entity::class);
        foreach ($mapper->getColumns() as $col) {
            if ($col->getProperty() === $property) {
                if (array_key_exists($col->getColumn(), $changes)) {
                    return $changes[$col->getColumn()]['old'];
                }
                return $mapper->getProperty($entity, $property);
            }
        }

        return null;
    }

    public function getDirtyProperties(object $entity): array
    {
        $changes = $this->unitOfWork->computeChangeSet($entity);

        if ($changes === []) {
            return [];
        }

        $mapper        = $this->mapperRegistry->get($entity::class);
        $colToProperty = [];
        foreach ($mapper->getColumns() as $col) {
            $colToProperty[$col->getColumn()] = $col->getProperty();
        }

        $properties = [];
        foreach (array_keys($changes) as $colName) {
            $properties[] = $colToProperty[$colName] ?? $colName;
        }

        return $properties;
    }

    public function addBatch(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->unitOfWork->add($entity);
        }
    }

    public function pushBatch(): int
    {
        $batchProcessor = new \Weaver\ORM\Persistence\BatchProcessor($this->connection);

        $grouped = [];
        foreach ($this->unitOfWork->getScheduledEntityInserts() as $entity) {
            $class = $entity::class;
            $mapper = $this->mapperRegistry->get($class);
            $table = $mapper->getTableName();

            $row = [];
            foreach ($mapper->getColumns() as $col) {
                if ($col->isAutoIncrement()) {
                    continue;
                }
                $row[$col->getColumn()] = $mapper->getProperty($entity, $col->getProperty());
            }

            $grouped[$table][] = $row;
        }

        $affected = 0;
        foreach ($grouped as $table => $rows) {
            $affected += $batchProcessor->insertBatch($table, $rows);
        }

        $this->unitOfWork->reset();

        return $affected;
    }

    public function getRepository(
        string $entityClass,
        string $repositoryClass = EntityRepository::class,
    ): EntityRepository {
        return $this->repositoryFactory->getRepository($this, $entityClass, $repositoryClass);
    }
}
