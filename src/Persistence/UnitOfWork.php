<?php

declare(strict_types=1);

namespace Weaver\ORM\Persistence;

use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Event\OnFlushEvent;
use Weaver\ORM\Exception\OptimisticLockException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Lifecycle\EntityLifecycleInvoker;
use Weaver\ORM\Mapping\Attribute\AfterAdd;
use Weaver\ORM\Mapping\Attribute\AfterDelete;
use Weaver\ORM\Mapping\Attribute\AfterLoad;
use Weaver\ORM\Mapping\Attribute\AfterUpdate;
use Weaver\ORM\Mapping\Attribute\BeforeAdd;
use Weaver\ORM\Mapping\Attribute\BeforeDelete;
use Weaver\ORM\Mapping\Attribute\BeforeUpdate;
use Weaver\ORM\Cache\SecondLevelCache;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\CascadeType;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;

final class UnitOfWork
{

    private array $new = [];

    private array $managed = [];

    private \WeakMap $snapshots;

    private array $stmtCache = [];

    private array $deleted = [];

    private array $entityClasses = [];

    private array $identityMap = [];

    private array $prePersistFired = [];

    private ?array $pendingChangeSets = null;

    public function __construct(
        private readonly \Weaver\ORM\DBAL\Connection $connection,
        private readonly MapperRegistry $registry,
        private readonly EntityHydrator $hydrator,
        private readonly LifecycleEventDispatcher $eventDispatcher,
        private readonly InsertOrderResolver $insertOrderResolver,
        private readonly EntityLifecycleInvoker $lifecycleInvoker = new EntityLifecycleInvoker(),
        private readonly CopyInserter $copyInserter = new CopyInserter(),
        private ?SecondLevelCache $secondLevelCache = null,
    ) {
        $this->snapshots = new \WeakMap();
    }

    public function setSecondLevelCache(?SecondLevelCache $cache): void
    {
        $this->secondLevelCache = $cache;
    }

    public function getSecondLevelCache(): ?SecondLevelCache
    {
        return $this->secondLevelCache;
    }

    public function add(object $entity): void
    {
        $oid   = (string) spl_object_id($entity);

        $class = $entity::class;

        $this->entityClasses[$oid] = $class;

        $mapper = $this->registry->get($class);

        foreach ($mapper->getColumns() as $col) {
            if (
                $col->isPrimary()
                && !$col->isAutoIncrement()
                && in_array($col->getType(), ['uuid', 'guid'], true)
                && $mapper->getProperty($entity, $col->getProperty()) === null
            ) {
                $mapper->setProperty($entity, $col->getProperty(), $this->generateUuid());
            }
        }

        $isNew = !isset($this->managed[$oid]);

        if ($isNew) {
            $this->new[$oid] = $entity;
        }

        if (!isset($this->snapshots[$entity])) {
            $this->snapshots[$entity] = $this->safeClone($entity);
        }

        if ($isNew && !isset($this->prePersistFired[$oid])) {
            $this->prePersistFired[$oid] = true;
            $this->lifecycleInvoker->invoke($entity, BeforeAdd::class);
            $this->eventDispatcher->dispatch('prePersist', $entity);
        }
    }

    public function delete(object $entity): void
    {
        $oid = (string) spl_object_id($entity);

        $this->deleted[$oid] = $entity;

        unset($this->new[$oid], $this->managed[$oid]);
    }

    public function push(?object $entity = null): void
    {

        if ($entity !== null) {
            $oid = (string) spl_object_id($entity);

            if (isset($this->managed[$oid])) {
                $this->pushManagedEntity($oid, $entity);
                return;
            }

            if (isset($this->deleted[$oid])) {
                $this->pushDeletedEntity($oid, $entity);
                return;
            }

            if (isset($this->new[$oid])) {
                $this->pushNewEntity($oid, $entity);
                return;
            }

            $seen = [];
            $this->cascadePersist($entity, $seen);

            $oid = (string) spl_object_id($entity);
            if (isset($this->new[$oid])) {
                $this->executeInsert($oid, $this->new[$oid]);
            }

            return;
        }

        $this->eventDispatcher->dispatchFlush('preFlush');

        $cascadePersistSeen = [];
        $entitiesToWalk = array_merge(
            array_values($this->new),
            array_values($this->managed),
        );
        foreach ($entitiesToWalk as $trackedEntity) {
            $this->cascadePersist($trackedEntity, $cascadePersistSeen);
        }

        $cascadeRemoveSeen = [];
        $entitiesToDelete = array_values($this->deleted);
        foreach ($entitiesToDelete as $deletedEntity) {
            $this->cascadeRemove($deletedEntity, $cascadeRemoveSeen);
        }

        $this->pendingChangeSets = [];

        foreach ($this->managed as $oid => $managedEntity) {
            $oid      = (string) $oid;
            $class    = $this->entityClasses[$oid];
            $mapper   = $this->registry->get($class);
            $snapshot = $this->snapshots[$managedEntity] ?? null;

            $diff           = [];
            $originalValues = [];

            if ($snapshot === null) {

                $diff = $this->hydrator->extract($managedEntity, $class);
                foreach (array_keys($diff) as $colName) {
                    $originalValues[$colName] = null;
                }
            } else {
                foreach ($mapper->getColumns() as $col) {
                    if ($col->isPrimary() || $col->isVirtual() || $col->isGenerated()) {
                        continue;
                    }
                    $newVal = $mapper->getProperty($managedEntity, $col->getProperty());
                    $oldVal = $mapper->getProperty($snapshot, $col->getProperty());
                    if ($newVal !== $oldVal) {
                        $diff[$col->getColumn()]           = $newVal;
                        $originalValues[$col->getColumn()] = $oldVal;
                    }
                }
            }

            if ($diff === []) {
                continue;
            }

            $changeSet = new ChangeSet($managedEntity, $class, $diff, $originalValues);
            $this->pendingChangeSets[$oid] = $changeSet;

            $this->lifecycleInvoker->invoke($managedEntity, BeforeUpdate::class);
            $this->eventDispatcher->dispatch('preUpdate', $managedEntity, $changeSet->getChanges());
        }

        $onFlushEvent = new OnFlushEvent($this);
        $this->eventDispatcher->dispatchOnFlush($onFlushEvent);

        $postOnFlushCascadeSeen = [];
        foreach (array_values($this->new) as $newAfterOnFlush) {
            $this->cascadePersist($newAfterOnFlush, $postOnFlushCascadeSeen);
        }

        $newClasses = [];
        foreach ($this->new as $oid => $newEntity) {
            $oid = (string) $oid;
            $newClasses[$oid] = $this->entityClasses[$oid];
        }

        $uniqueClasses  = array_unique(array_values($newClasses));
        $orderedClasses = $this->insertOrderResolver->resolve($uniqueClasses);

        $byClass = [];
        foreach ($newClasses as $oid => $class) {
            $byClass[$class][$oid] = $this->new[$oid];
        }

        foreach ($orderedClasses as $class) {
            if (!isset($byClass[$class])) {
                continue;
            }

            $entities = $byClass[$class];

            if (count($entities) === 1) {

                $oid = array_key_first($entities);
                $this->executeInsert((string) $oid, $this->new[$oid]);
                continue;
            }

            $mapper = $this->registry->get($class);
            $this->executeBatchInsert($entities, $mapper);
        }

        $updateGroups = [];

        foreach ($this->pendingChangeSets as $oid => $changeSet) {
            $oid    = (string) $oid;
            $class  = $changeSet->getEntityClass();
            $mapper = $this->registry->get($class);

            if ($mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper && $mapper->isComposite()) {
                $this->executeUpdate($oid, $changeSet);
                continue;
            }

            if (
                $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
                && $mapper->getInheritanceMapping()?->type === 'JOINED'
                && $mapper->getInheritanceJoinTable() !== null
            ) {
                $this->executeUpdate($oid, $changeSet);
                continue;
            }

            $hasSoftDelete = false;
            foreach ($mapper->getColumns() as $col) {
                if ($col->getColumn() === 'deleted_at' && !$col->isVirtual() && !$col->isGenerated()) {
                    $hasSoftDelete = true;
                    break;
                }
            }
            if ($hasSoftDelete) {
                $this->executeUpdate($oid, $changeSet);
                continue;
            }

            $hasVersion = false;
            foreach ($mapper->getColumns() as $col) {
                if ($col->isVersion()) {
                    $hasVersion = true;
                    break;
                }
            }
            if ($hasVersion) {
                $this->executeUpdate($oid, $changeSet);
                continue;
            }

            $dirtyColKeys = array_keys($changeSet->getChanges());
            sort($dirtyColKeys);
            $groupKey = $class . ':' . implode('+', $dirtyColKeys);
            $updateGroups[$groupKey][] = [$oid, $changeSet];
        }

        foreach ($updateGroups as $group) {
            if (count($group) === 1) {
                [$oid, $changeSet] = $group[0];
                $this->executeUpdate($oid, $changeSet);
            } else {

                $groupClass  = $group[0][1]->getEntityClass();
                $groupMapper = $this->registry->get($groupClass);
                $this->executeBulkUpdate($group, $groupMapper);
            }
        }

        foreach ($this->deleted as $oid => $deletedEntity) {
            $this->executeDelete((string) $oid, $deletedEntity);
        }

        $this->new               = [];
        $this->deleted           = [];
        $this->prePersistFired   = [];
        $this->pendingChangeSets = null;

        $this->eventDispatcher->dispatchFlush('postFlush');
    }

    public function reset(): void
    {
        $this->new             = [];
        $this->managed         = [];
        $this->snapshots       = new \WeakMap();
        $this->deleted         = [];
        $this->entityClasses   = [];
        $this->identityMap     = [];
        $this->prePersistFired = [];

        $this->eventDispatcher->dispatchFlush('onClear');
        $this->clearStatementCache();
    }

    public function clearStatementCache(): void
    {
        $this->stmtCache = [];
    }

    public function untrack(object $entity): void
    {
        $seen = [];
        $this->cascadeDetach($entity, $seen);
    }

    private function cascadeDetach(object $entity, array &$seen): void
    {
        $oid = spl_object_id($entity);

        if (isset($seen[$oid])) {
            return;
        }

        $seen[$oid] = true;

        $strOid = (string) $oid;

        unset(
            $this->new[$strOid],
            $this->managed[$strOid],
            $this->snapshots[$entity],
            $this->deleted[$strOid],
            $this->entityClasses[$strOid],
            $this->prePersistFired[$strOid],
        );

        $class = $entity::class;

        if (!$this->registry->has($class)) {
            return;
        }

        $mapper = $this->registry->get($class);

        foreach ($mapper->getRelations() as $relation) {
            if (!$relation->hasCascade(CascadeType::Detach)) {
                continue;
            }

            $propertyName = $relation->getProperty();

            try {
                $value = $mapper->getProperty($entity, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $related = [];

            if (is_object($value)) {
                $related = [$value];
            } elseif (is_array($value) || $value instanceof \Traversable) {
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $related[] = $item;
                    }
                }
            }

            foreach ($related as $relatedEntity) {
                $this->cascadeDetach($relatedEntity, $seen);
            }
        }
    }

    public function isTracked(object $entity): bool
    {
        $oid = (string) spl_object_id($entity);

        return isset($this->new[$oid]) || isset($this->managed[$oid]);
    }

    public function findInIdentityMap(string $entityClass, int|string $id): ?object
    {
        return $this->identityMap[$entityClass][(string) $id] ?? null;
    }

    public function registerInIdentityMap(object $entity, string $entityClass, int|string $id): void
    {
        $this->identityMap[$entityClass][(string) $id] = $entity;
    }

    public function getScheduledEntityInserts(): array
    {
        return array_values($this->new);
    }

    public function getScheduledEntityUpdates(): array
    {
        if ($this->pendingChangeSets === null) {
            return [];
        }

        return array_values(array_map(
            static fn (ChangeSet $cs): object => $cs->getEntity(),
            $this->pendingChangeSets,
        ));
    }

    public function getScheduledEntityDeletes(): array
    {
        return array_values($this->deleted);
    }

    public function recomputeSingleEntityChangeset(object $entity): void
    {
        $oid = (string) spl_object_id($entity);

        if (!isset($this->managed[$oid])) {
            return;
        }

        $class    = $this->entityClasses[$oid];
        $mapper   = $this->registry->get($class);
        $snapshot = $this->snapshots[$entity] ?? null;

        $diff           = [];
        $originalValues = [];

        if ($snapshot === null) {
            $diff = $this->hydrator->extract($entity, $class);
            foreach (array_keys($diff) as $colName) {
                $originalValues[$colName] = null;
            }
        } else {
            foreach ($mapper->getColumns() as $col) {
                if ($col->isPrimary() || $col->isVirtual() || $col->isGenerated()) {
                    continue;
                }
                $newVal = $mapper->getProperty($entity, $col->getProperty());
                $oldVal = $mapper->getProperty($snapshot, $col->getProperty());
                if ($newVal !== $oldVal) {
                    $diff[$col->getColumn()]           = $newVal;
                    $originalValues[$col->getColumn()] = $oldVal;
                }
            }
        }

        if ($diff === []) {

            if ($this->pendingChangeSets !== null) {
                unset($this->pendingChangeSets[$oid]);
            }
            return;
        }

        $changeSet = new ChangeSet($entity, $class, $diff, $originalValues);

        if ($this->pendingChangeSets !== null) {
            $this->pendingChangeSets[$oid] = $changeSet;
        }
    }

    public function reload(object $entity): void
    {
        $oid    = (string) spl_object_id($entity);

        $class  = $this->entityClasses[$oid] ?? $entity::class;
        $mapper = $this->registry->get($class);
        $table  = $mapper->getTableName();
        $pkCol  = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $pkType = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getType() : \Weaver\ORM\DBAL\ParameterType::INTEGER;
        $pkValue = $mapper->getProperty($entity, $pkProp);

        $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $table, $pkCol);
        $row = $this->connection->fetchAssociative($sql, [$pkValue], [$pkType]);

        if ($row === false) {
            throw \Weaver\ORM\Exception\EntityNotFoundException::forId($class, $pkValue);
        }

        foreach ($mapper->getColumns() as $col) {
            if (!array_key_exists($col->getColumn(), $row)) {
                continue;
            }

            $rawValue = $row[$col->getColumn()];

            if ($rawValue !== null) {
                $phpValue = \Weaver\ORM\DBAL\Type\Type::getType($col->getType())
                    ->convertToPHPValue($rawValue, $this->connection->getDatabasePlatform());

                $enumClass = $col->getEnumClass();
                if ($enumClass !== null) {

                    $scalarValue = is_int($phpValue) ? $phpValue : (is_scalar($phpValue) ? (string) $phpValue : '');
                    $phpValue = $enumClass::from($scalarValue);
                }
            } else {
                $phpValue = null;
            }

            $mapper->setProperty($entity, $col->getProperty(), $phpValue);
        }

        $this->entityClasses[$oid] = $class;
        $this->snapshots[$entity]  = $this->safeClone($entity);

        if (!isset($this->new[$oid])) {
            $this->managed[$oid] = $entity;
        }

        $this->lifecycleInvoker->invoke($entity, AfterLoad::class);
        $this->eventDispatcher->dispatch('postLoad', $entity);
    }

    public function merge(object $detachedEntity): object
    {

        $class  = $detachedEntity::class;
        $mapper = $this->registry->get($class);
        $pkCol  = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $id     = $mapper->getProperty($detachedEntity, $pkProp);

        foreach ($this->managed as $oid => $managedEntity) {
            if ($managedEntity::class === $class
                && $mapper->getProperty($managedEntity, $pkProp) == $id) {

                foreach ($mapper->getColumns() as $col) {
                    $val = $mapper->getProperty($detachedEntity, $col->getProperty());
                    $mapper->setProperty($managedEntity, $col->getProperty(), $val);
                }
                return $managedEntity;
            }
        }

        if ($id !== null) {
            $pkType = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getType() : \Weaver\ORM\DBAL\ParameterType::INTEGER;
            $sql    = sprintf('SELECT * FROM %s WHERE %s = ?', $mapper->getTableName(), $pkCol);
            $row    = $this->connection->fetchAssociative($sql, [$id], [$pkType]);

            if ($row !== false) {
                $managed = $this->hydrator->hydrate($class, $row);

                $this->track($managed, $class);

                foreach ($mapper->getColumns() as $col) {
                    $val = $mapper->getProperty($detachedEntity, $col->getProperty());
                    $mapper->setProperty($managed, $col->getProperty(), $val);
                }
                return $managed;
            }
        }

        $this->add($detachedEntity);
        return $detachedEntity;
    }

    public function upsert(object $entity): void
    {
        $oid   = (string) spl_object_id($entity);

        $class  = $entity::class;
        $mapper = $this->registry->get($class);
        $table  = $mapper->getTableName();

        $pkColumn = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkColumn);
        $isAutoInc = $pkColDef instanceof ColumnDefinition && $pkColDef->isAutoIncrement();

        $this->entityClasses[$oid] = $class;

        if (!isset($this->prePersistFired[$oid])) {
            $this->prePersistFired[$oid] = true;
            $this->lifecycleInvoker->invoke($entity, BeforeAdd::class);
            $this->eventDispatcher->dispatch('prePersist', $entity);
        }

        $data = $this->hydrator->extract($entity, $class);

        $now = new \DateTimeImmutable();
        foreach ($mapper->getColumns() as $col) {
            if (
                in_array($col->getColumn(), ['created_at', 'updated_at'], true)
                && !$col->isVirtual()
                && !$col->isGenerated()
            ) {
                $mapper->setProperty($entity, $col->getProperty(), $now);
                $data[$col->getColumn()] = $now->format('Y-m-d H:i:s');
            }
        }

        $columns = array_keys($data);
        $params  = array_values($data);
        $types   = $this->buildTypesForColumns($mapper->getPersistableColumns(), $columns);

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList   = implode(', ', array_map(
            fn (string $c): string => $this->connection->quoteIdentifier($c),
            $columns,
        ));

        $driverClass = get_class($this->connection->getDriver());
        $isMySQL = str_contains(strtolower($driverClass), 'mysql');

        $updateCols = [];
        foreach ($columns as $col) {
            $colDef = $mapper->getColumnByName($col);
            if ($colDef instanceof ColumnDefinition && ($colDef->isPrimary() || $colDef->isAutoIncrement())) {
                continue;
            }
            $updateCols[] = $col;
        }

        if ($updateCols === []) {

            $conflictClause = $isMySQL
                ? ''
                : sprintf(' ON CONFLICT (%s) DO NOTHING', $pkColumn);

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)%s',
                $table,
                $columnList,
                $placeholders,
                $conflictClause,
            );
        } elseif ($isMySQL) {
            $updateParts = array_map(
                static fn (string $c): string => sprintf('%s = VALUES(%s)', $c, $c),
                $updateCols,
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                $columnList,
                $placeholders,
                implode(', ', $updateParts),
            );
        } else {

            $updateParts = array_map(
                static fn (string $c): string => sprintf('%s = EXCLUDED.%s', $c, $c),
                $updateCols,
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $table,
                $columnList,
                $placeholders,
                $pkColumn,
                implode(', ', $updateParts),
            );
        }

        $this->connection->executeStatement($sql, $params, $types);

        if ($isAutoInc && $pkColDef instanceof ColumnDefinition) {
            $pkProp  = $pkColDef->getProperty();
            $current = $mapper->getProperty($entity, $pkProp);
            if ($current === null || $current === 0) {
                $lastId = $this->connection->lastInsertId();
                if ($lastId !== false && $lastId !== '0' && $lastId !== 0) {
                    $mapper->setProperty($entity, $pkProp, (int) $lastId);
                }
            }
        }

        unset($this->new[$oid]);
        $this->managed[$oid]      = $entity;
        $this->snapshots[$entity] = $this->safeClone($entity);

        $this->lifecycleInvoker->invoke($entity, AfterAdd::class);
        $this->eventDispatcher->dispatch('postPersist', $entity);
    }

    public function track(object $entity, string $entityClass): void
    {
        $oid = (string) spl_object_id($entity);

        $this->entityClasses[$oid] = $entityClass;
        $this->managed[$oid]       = $entity;
        $this->snapshots[$entity]  = $this->safeClone($entity);

        $mapper = $this->registry->get($entityClass);
        $pk = $mapper->getPrimaryKey();
        $pkVal = $entity->$pk ?? null;
        if ($pkVal !== null) {
            $this->identityMap[$entityClass][(string) $pkVal] = $entity;
        }
    }

    public function computeChangeSet(object $entity): array
    {
        $oid = (string) spl_object_id($entity);

        if (!isset($this->managed[$oid])) {
            return [];
        }

        $class    = $this->entityClasses[$oid];
        $mapper   = $this->registry->get($class);
        $snapshot = $this->snapshots[$entity] ?? null;

        $result = [];

        if ($snapshot === null) {

            $extracted = $this->hydrator->extract($entity, $class);
            foreach ($extracted as $colName => $newVal) {
                $result[$colName] = ['old' => null, 'new' => $newVal];
            }
        } else {
            foreach ($mapper->getColumns() as $col) {
                if ($col->isPrimary() || $col->isVirtual() || $col->isGenerated()) {
                    continue;
                }
                $newVal = $mapper->getProperty($entity, $col->getProperty());
                $oldVal = $mapper->getProperty($snapshot, $col->getProperty());
                if ($newVal !== $oldVal) {
                    $result[$col->getColumn()] = ['old' => $oldVal, 'new' => $newVal];
                }
            }
        }

        return $result;
    }

    public function isEntityDirty(object $entity): bool
    {
        return $this->computeChangeSet($entity) !== [];
    }

    public function isEntityNew(object $entity): bool
    {
        $oid = (string) spl_object_id($entity);

        return isset($this->new[$oid]);
    }

    public function isEntityManaged(object $entity): bool
    {
        $oid = (string) spl_object_id($entity);

        return isset($this->managed[$oid]);
    }

    public function isEntityDeleted(object $entity): bool
    {
        $oid = (string) spl_object_id($entity);

        return isset($this->deleted[$oid]);
    }

    private array $insertMetaCache = [];

    private function getInsertMeta(string $class): array
    {
        if (isset($this->insertMetaCache[$class])) {
            return $this->insertMetaCache[$class];
        }

        $mapper = $this->registry->get($class);
        $table = $mapper->getTableName();
        $pkColumn = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkColumn);
        $isAutoInc = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition && $pkColDef->isAutoIncrement();

        $hasTimestamps = false;
        $timestampProps = [];
        foreach ($mapper->getColumns() as $col) {
            if (in_array($col->getColumn(), ['created_at', 'updated_at'], true) && !$col->isVirtual() && !$col->isGenerated()) {
                $hasTimestamps = true;
                $timestampProps[$col->getColumn()] = $col->getProperty();
            }
        }

        $isJoined = $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
            && $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null;

        return $this->insertMetaCache[$class] = [
            $mapper, $table, $pkColumn, $pkColDef, $isAutoInc,
            $hasTimestamps, $timestampProps, $isJoined,
        ];
    }

    private function executeInsert(string $oid, object $entity): void
    {
        $class = $this->entityClasses[$oid];
        [$mapper, $table, $pkColumn, $pkColDef, $isAutoInc, $hasTimestamps, $timestampProps, $isJoined] = $this->getInsertMeta($class);

        if (!isset($this->prePersistFired[$oid])) {
            $this->lifecycleInvoker->invoke($entity, BeforeAdd::class);
            $this->eventDispatcher->dispatch('prePersist', $entity);
        }

        $data = $this->hydrator->extract($entity, $class);

        if ($hasTimestamps) {
            $now = new \DateTimeImmutable();
            $formatted = $now->format('Y-m-d H:i:s');
            foreach ($timestampProps as $col => $prop) {
                $mapper->setProperty($entity, $prop, $now);
                $data[$col] = $formatted;
            }
        }

        if ($isJoined) {
            $ownColNames = [];
            foreach ($mapper->getOwnColumns() as $col) {
                if (!$col->isPrimary()) {
                    $ownColNames[$col->getColumn()] = true;
                }
            }
            foreach (array_keys($data) as $colName) {
                if (isset($ownColNames[$colName])) {
                    unset($data[$colName]);
                }
            }
        }

        $this->connection->insert($table, $data);

        if ($isAutoInc && $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition) {
            $lastId = $this->connection->lastInsertId();
            $mapper->setProperty($entity, $pkColDef->getProperty(), (int) $lastId);
        }

        if (
            $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
            && $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null
        ) {
            $joinTable  = $mapper->getInheritanceJoinTable();
            $joinKey    = $mapper->getInheritanceJoinKey();
            $ownCols    = $mapper->getOwnColumns();
            $pkValue    = $mapper->getProperty(
                $entity,
                $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition
                    ? $pkColDef->getProperty()
                    : $pkColumn,
            );

            $childData = [$joinKey => $pkValue];
            foreach ($ownCols as $col) {
                if (!$col->isPrimary() && !$col->isGenerated() && !$col->isVirtual()) {
                    $childData[$col->getColumn()] = $mapper->getProperty($entity, $col->getProperty());
                }
            }

            $childColumns = array_keys($childData);
            $childParams  = array_values($childData);
            $childTypes   = [];
            foreach ($childColumns as $cn) {
                $colDef = $mapper->getColumnByName($cn);
                $childTypes[] = $colDef !== null ? $colDef->getType() : \Weaver\ORM\DBAL\ParameterType::STRING;
            }

            $childPlaceholders = implode(', ', array_fill(0, count($childColumns), '?'));
            $childColList      = implode(', ', array_map(
                fn (string $c): string => $this->connection->quoteIdentifier($c),
                $childColumns,
            ));

            $childSql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $joinTable, $childColList, $childPlaceholders);
            $childCacheKey = $joinTable . ':insert:' . implode(',', $childColumns);
            $childStmt = $this->stmtCache[$childCacheKey] ??= $this->connection->prepare($childSql);
            foreach ($childParams as $i => $val) {
                $childStmt->bindValue($i + 1, $val, $childTypes[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
            }
            $childStmt->executeStatement();
        }

        unset($this->new[$oid], $this->prePersistFired[$oid]);
        $this->managed[$oid] = $entity;

        $this->snapshots[$entity] = $this->safeClone($entity);

        $pkVal = $mapper->getProperty($entity, $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getProperty() : $pkColumn);
        if ($pkVal !== null) {
            $this->identityMap[$class][(string) $pkVal] = $entity;
        }

        $this->lifecycleInvoker->invoke($entity, AfterAdd::class);
        $this->eventDispatcher->dispatch('postPersist', $entity);

        $this->updateSecondLevelCache($entity, $class);
    }

    private function executeBatchInsert(array $entities, AbstractEntityMapper $mapper): void
    {
        $class    = $mapper->getEntityClass();
        $table    = $mapper->getTableName();
        $pkColumn = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkColumn);
        $isAutoInc = $pkColDef instanceof ColumnDefinition && $pkColDef->isAutoIncrement();

        $isJoined = $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null;

        if ($isJoined || $mapper->isComposite()) {
            foreach ($entities as $oid => $entity) {
                $this->executeInsert((string) $oid, $entity);
            }
            return;
        }

        $now = new \DateTimeImmutable();

        $rows = [];
        foreach ($entities as $oid => $entity) {
            $oid = (string) $oid;

            if (!isset($this->prePersistFired[$oid])) {
                $this->lifecycleInvoker->invoke($entity, BeforeAdd::class);
                $this->eventDispatcher->dispatch('prePersist', $entity);
            }

            $data = $this->hydrator->extract($entity, $class);

            foreach ($mapper->getColumns() as $col) {
                if (
                    in_array($col->getColumn(), ['created_at', 'updated_at'], true)
                    && !$col->isVirtual()
                    && !$col->isGenerated()
                ) {
                    $mapper->setProperty($entity, $col->getProperty(), $now);
                    $data[$col->getColumn()] = $now->format('Y-m-d H:i:s');
                }
            }

            $rows[$oid] = $data;
        }

        if ($this->copyInserter->supports($this->connection, $entities)) {

            $pkColumn    = $mapper->getPrimaryKey();
            $allPksSet   = true;
            foreach ($rows as $data) {
                if (array_key_exists($pkColumn, $data) && $data[$pkColumn] === null) {
                    $allPksSet = false;
                    break;
                }
            }

            if ($allPksSet) {
                $this->copyInserter->insert($this->connection, $mapper, $rows, $entities);

                foreach ($entities as $oid => $entity) {
                    $oid = (string) $oid;

                    unset($this->new[$oid], $this->prePersistFired[$oid]);
                    $this->managed[$oid]      = $entity;
                    $this->snapshots[$entity] = $this->safeClone($entity);

                    $this->lifecycleInvoker->invoke($entity, AfterAdd::class);
                    $this->eventDispatcher->dispatch('postPersist', $entity);
                }

                return;
            }
        }

        $columns  = array_keys(reset($rows));
        $types    = $this->buildTypesForColumns($mapper->getPersistableColumns(), $columns);
        $colCount = count($columns);

        $columnList = implode(', ', array_map(
            fn (string $c): string => $this->connection->quoteIdentifier($c),
            $columns,
        ));

        $rowPlaceholder  = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
        $rowCount        = count($rows);
        $allPlaceholders = implode(', ', array_fill(0, $rowCount, $rowPlaceholder));

        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $table, $columnList, $allPlaceholders);

        $params = [];
        $flatTypes = [];
        foreach ($rows as $data) {
            foreach ($columns as $i => $colName) {
                $params[]    = $data[$colName] ?? null;
                $flatTypes[] = $types[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING;
            }
        }

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, $flatTypes[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
        }
        $stmt->executeStatement();

        if ($isAutoInc && $pkColDef instanceof ColumnDefinition) {
            $lastId  = (int) $this->connection->lastInsertId();
            $count   = count($entities);
            $firstId = $lastId - ($count - 1);
            $offset  = 0;
            foreach ($entities as $oid => $entity) {
                $mapper->setProperty($entity, $pkColDef->getProperty(), $firstId + $offset);
                $offset++;
            }
        }

        foreach ($entities as $oid => $entity) {
            $oid = (string) $oid;

            unset($this->new[$oid], $this->prePersistFired[$oid]);
            $this->managed[$oid]      = $entity;
            $this->snapshots[$entity] = $this->safeClone($entity);

            $this->lifecycleInvoker->invoke($entity, AfterAdd::class);
            $this->eventDispatcher->dispatch('postPersist', $entity);
        }
    }

    private function executeUpdate(string $oid, ChangeSet $changeSet): void
    {
        $entity  = $changeSet->getEntity();
        $class   = $changeSet->getEntityClass();
        $mapper  = $this->registry->get($class);
        $table   = $mapper->getTableName();
        $pkCol   = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp  = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $pkValue = $mapper->getProperty($entity, $pkProp);

        $changes = $changeSet->getChanges();

        $now = new \DateTimeImmutable();
        foreach ($mapper->getColumns() as $col) {
            if (
                $col->getColumn() === 'updated_at'
                && !$col->isVirtual()
                && !$col->isGenerated()
            ) {
                $mapper->setProperty($entity, $col->getProperty(), $now);
                $changes[$col->getColumn()] = $now->format('Y-m-d H:i:s');
            }
        }

        $versionColDef = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->isVersion()) {
                $versionColDef = $col;
                break;
            }
        }

        $params = [];
        $types  = [];
        $sets   = [];

        $persistableCols = [];
        foreach ($mapper->getPersistableColumns() as $col) {
            $persistableCols[$col->getColumn()] = $col;
        }

        $joinedOwnColNames = [];
        if (
            $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
            && $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null
        ) {
            foreach ($mapper->getOwnColumns() as $col) {
                if (!$col->isPrimary()) {
                    $joinedOwnColNames[$col->getColumn()] = true;
                }
            }
        }

        foreach ($changes as $colName => $newValue) {

            if ($versionColDef !== null && $colName === $versionColDef->getColumn()) {
                continue;
            }

            if (isset($joinedOwnColNames[$colName])) {
                continue;
            }

            $sets[]   = sprintf('%s = ?', $colName);
            $params[] = $newValue;
            $types[]  = isset($persistableCols[$colName])
                ? $persistableCols[$colName]->getType()
                : \Weaver\ORM\DBAL\ParameterType::STRING;
        }

        if ($sets === [] && $versionColDef === null) {
            return;
        }

        $expectedVersion = null;
        if ($versionColDef !== null) {
            $rawVersion = $mapper->getProperty($entity, $versionColDef->getProperty());
            $expectedVersion = is_numeric($rawVersion) ? (int) $rawVersion : 0;
            $newVersion      = $expectedVersion + 1;

            $sets[]   = sprintf('%s = ?', $versionColDef->getColumn());
            $params[] = $newVersion;
            $types[]  = $versionColDef->getType();
        }

        $whereClause = '';

        if ($mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper && $mapper->isComposite()) {

            $whereParts = [];
            foreach ($mapper->getColumns() as $col) {
                if (!$col->isPrimary()) {
                    continue;
                }
                $colValue   = $mapper->getProperty($entity, $col->getProperty());
                $params[]   = $colValue;
                $types[]    = $col->getType();
                $whereParts[] = sprintf('%s = ?', $col->getColumn());
            }
            $whereClause = implode(' AND ', $whereParts);
        } else {
            $params[] = $pkValue;
            $pkType   = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getType() : \Weaver\ORM\DBAL\ParameterType::INTEGER;
            $types[]  = $pkType;
            $whereClause = sprintf('%s = ?', $pkCol);
        }

        if ($versionColDef !== null && $expectedVersion !== null) {
            $whereClause .= sprintf(' AND %s = ?', $versionColDef->getColumn());
            $params[]     = $expectedVersion;
            $types[]      = $versionColDef->getType();
        }

        if ($sets !== []) {
            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s',
                $table,
                implode(', ', $sets),
                $whereClause,
            );

            $setCols = [];
            foreach ($changes as $colName => $_) {
                if ($versionColDef !== null && $colName === $versionColDef->getColumn()) {
                    continue;
                }
                if (isset($joinedOwnColNames[$colName])) {
                    continue;
                }
                $setCols[] = $colName;
            }
            sort($setCols);
            $cacheKey = $table . ':update:' . implode('+', $setCols);
            $stmt = $this->stmtCache[$cacheKey] ??= $this->connection->prepare($sql);
            foreach ($params as $i => $val) {
                $stmt->bindValue($i + 1, $val, $types[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
            }
            $affected = $stmt->executeStatement();

            if ($versionColDef !== null && $affected === 0) {
                throw OptimisticLockException::lockFailed($class, $pkValue, $expectedVersion ?? 0);
            }

            if ($versionColDef !== null) {
                $mapper->setProperty($entity, $versionColDef->getProperty(), $expectedVersion + 1);
            }
        }

        if (
            $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
            && $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null
        ) {
            $joinTable   = $mapper->getInheritanceJoinTable();
            $joinKey     = $mapper->getInheritanceJoinKey();
            $ownCols     = $mapper->getOwnColumns();

            $ownColNames = [];
            foreach ($ownCols as $col) {
                if (!$col->isPrimary() && !$col->isGenerated() && !$col->isVirtual()) {
                    $ownColNames[$col->getColumn()] = $col;
                }
            }

            $childSets   = [];
            $childParams = [];
            $childTypes  = [];
            foreach ($changes as $colName => $newValue) {
                if (!isset($ownColNames[$colName])) {
                    continue;
                }
                $childSets[]   = sprintf('%s = ?', $colName);
                $childParams[] = $newValue;
                $childTypes[]  = $ownColNames[$colName]->getType();
            }

            if ($childSets !== []) {
                $pkColDef2  = $mapper->getColumnByName($pkCol);
                $pkType2    = $pkColDef2 instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef2->getType() : \Weaver\ORM\DBAL\ParameterType::INTEGER;
                $childParams[] = $pkValue;
                $childTypes[]  = $pkType2;

                $childUpdateSql = sprintf(
                    'UPDATE %s SET %s WHERE %s = ?',
                    $joinTable,
                    implode(', ', $childSets),
                    $joinKey,
                );

                $dirtyChildCols = [];
                foreach ($changes as $colName => $_) {
                    if (isset($ownColNames[$colName])) {
                        $dirtyChildCols[] = $colName;
                    }
                }
                sort($dirtyChildCols);
                $childCacheKey = $joinTable . ':update:' . implode('+', $dirtyChildCols);
                $childUpdateStmt = $this->stmtCache[$childCacheKey] ??= $this->connection->prepare($childUpdateSql);
                foreach ($childParams as $i => $val) {
                    $childUpdateStmt->bindValue($i + 1, $val, $childTypes[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
                }
                $childUpdateStmt->executeStatement();
            }
        }

        $this->snapshots[$entity] = $this->safeClone($entity);

        $this->lifecycleInvoker->invoke($entity, AfterUpdate::class);
        $this->eventDispatcher->dispatch('postUpdate', $entity, $changeSet->getChanges());

        $this->updateSecondLevelCache($entity, $changeSet->getEntityClass());
    }

    private function executeBulkUpdate(array $group, AbstractEntityMapper $mapper): void
    {
        $table    = $mapper->getTableName();
        $pkCol    = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp   = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition
            ? $pkColDef->getProperty()
            : $pkCol;

        $dirtyColumns = array_keys($group[0][1]->getChanges());

        $persistableCols = [];
        foreach ($mapper->getPersistableColumns() as $col) {
            $persistableCols[$col->getColumn()] = $col;
        }

        $now = new \DateTimeImmutable();
        $updatedAtCol = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->getColumn() === 'updated_at' && !$col->isVirtual() && !$col->isGenerated()) {
                $updatedAtCol = $col;
                break;
            }
        }

        $hasUpdatedAt = in_array('updated_at', $dirtyColumns, true);

        $entityChanges = [];
        foreach ($group as $i => [$oid, $changeSet]) {
            $entityChanges[$i] = $changeSet->getChanges();
        }

        if ($updatedAtCol !== null) {
            $updatedAtColType = $updatedAtCol->getType();
            $nowValue = match (true) {
                str_contains($updatedAtColType, 'datetime') || str_contains($updatedAtColType, 'date') => $now,
                default => $now->format('Y-m-d H:i:s'),
            };

            if (!$hasUpdatedAt) {
                $dirtyColumns[] = 'updated_at';
            }
            foreach ($group as $i => [$oid, $changeSet]) {
                $entity = $changeSet->getEntity();
                $mapper->setProperty($entity, $updatedAtCol->getProperty(), $now);
                $entityChanges[$i]['updated_at'] = $nowValue;
            }
        }

        $setClauses = [];
        $params     = [];
        $types      = [];

        foreach ($dirtyColumns as $col) {
            $colType = isset($persistableCols[$col])
                ? $persistableCols[$col]->getType()
                : 'string';

            $pkType = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition
                ? $pkColDef->getType()
                : 'integer';

            $whenFragments = [];
            foreach ($group as $i => [$oid, $changeSet]) {
                $entity  = $changeSet->getEntity();
                $pkValue = $mapper->getProperty($entity, $pkProp);

                $whenFragments[] = 'WHEN ? THEN ?';
                $params[]        = $pkValue;
                $types[]         = $pkType;
                $params[]        = $entityChanges[$i][$col] ?? null;
                $types[]         = $colType;
            }

            $setClauses[] = sprintf(
                '%s = CASE %s %s END',
                $col,
                $pkCol,
                implode(' ', $whenFragments),
            );
        }

        $ids     = [];
        $idTypes = [];
        $pkType  = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition
            ? $pkColDef->getType()
            : 'integer';

        foreach ($group as [$oid, $changeSet]) {
            $entity  = $changeSet->getEntity();
            $ids[]   = $mapper->getProperty($entity, $pkProp);
            $idTypes[] = $pkType;
        }

        $inPlaceholders = implode(', ', array_fill(0, count($ids), '?'));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s IN (%s)',
            $table,
            implode(', ', $setClauses),
            $pkCol,
            $inPlaceholders,
        );

        $allParams = array_merge($params, $ids);
        $allTypes  = array_merge($types, $idTypes);

        $this->connection->executeStatement($sql, $allParams, $allTypes);

        foreach ($group as [$oid, $changeSet]) {
            $entity = $changeSet->getEntity();
            $this->snapshots[$entity] = $this->safeClone($entity);
            $this->lifecycleInvoker->invoke($entity, AfterUpdate::class);
            $this->eventDispatcher->dispatch('postUpdate', $entity, $changeSet->getChanges());
        }
    }

    private function executeDelete(string $oid, object $entity): void
    {

        $class    = $this->entityClasses[$oid] ?? $entity::class;
        $mapper   = $this->registry->get($class);
        $table    = $mapper->getTableName();
        $pkCol    = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp   = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $pkValue  = $mapper->getProperty($entity, $pkProp);
        $pkType   = $pkColDef instanceof \Weaver\ORM\Mapping\ColumnDefinition ? $pkColDef->getType() : \Weaver\ORM\DBAL\ParameterType::INTEGER;

        $this->lifecycleInvoker->invoke($entity, BeforeDelete::class);
        $this->eventDispatcher->dispatch('preRemove', $entity);

        $deletedAtCol = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->getColumn() === 'deleted_at' && !$col->isVirtual() && !$col->isGenerated()) {
                $deletedAtCol = $col;
                break;
            }
        }

        $whereParams = [];
        $whereTypes  = [];
        $whereClause = '';

        if ($mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper && $mapper->isComposite()) {
            $whereParts = [];
            foreach ($mapper->getColumns() as $col) {
                if (!$col->isPrimary()) {
                    continue;
                }
                $colValue      = $mapper->getProperty($entity, $col->getProperty());
                $whereParams[] = $colValue;
                $whereTypes[]  = $col->getType();
                $whereParts[]  = sprintf('%s = ?', $col->getColumn());
            }
            $whereClause = implode(' AND ', $whereParts);
        } else {
            $whereParams[] = $pkValue;
            $whereTypes[]  = $pkType;
            $whereClause   = sprintf('%s = ?', $pkCol);
        }

        if (
            $mapper instanceof \Weaver\ORM\Mapping\AbstractEntityMapper
            && $mapper->getInheritanceMapping()?->type === 'JOINED'
            && $mapper->getInheritanceJoinTable() !== null
        ) {
            $joinTable = $mapper->getInheritanceJoinTable();
            $joinKey   = $mapper->getInheritanceJoinKey();
            $joinDeleteSql = sprintf('DELETE FROM %s WHERE %s = ?', $joinTable, $joinKey);
            $joinDeleteKey = $joinTable . ':delete';
            $joinDeleteStmt = $this->stmtCache[$joinDeleteKey] ??= $this->connection->prepare($joinDeleteSql);
            $joinDeleteStmt->bindValue(1, $pkValue, $pkType);
            $joinDeleteStmt->executeStatement();
        }

        if ($deletedAtCol !== null) {
            $now    = new \DateTimeImmutable();
            $sdSql  = sprintf('UPDATE %s SET "deleted_at" = ? WHERE %s', $table, $whereClause);
            $sdKey  = $table . ':soft-delete';
            $sdStmt = $this->stmtCache[$sdKey] ??= $this->connection->prepare($sdSql);
            $sdParams = array_merge([$now->format('Y-m-d H:i:s')], $whereParams);
            $sdTypes  = array_merge([\Weaver\ORM\DBAL\ParameterType::STRING], $whereTypes);
            foreach ($sdParams as $i => $val) {
                $sdStmt->bindValue($i + 1, $val, $sdTypes[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
            }
            $sdStmt->executeStatement();

            $mapper->setProperty($entity, $deletedAtCol->getProperty(), $now);
        } else {
            $delSql  = sprintf('DELETE FROM %s WHERE %s', $table, $whereClause);
            $delKey  = $table . ':delete';
            $delStmt = $this->stmtCache[$delKey] ??= $this->connection->prepare($delSql);
            foreach ($whereParams as $i => $val) {
                $delStmt->bindValue($i + 1, $val, $whereTypes[$i] ?? \Weaver\ORM\DBAL\ParameterType::STRING);
            }
            $delStmt->executeStatement();
        }

        unset($this->snapshots[$entity], $this->entityClasses[$oid]);

        $this->lifecycleInvoker->invoke($entity, AfterDelete::class);
        $this->eventDispatcher->dispatch('postRemove', $entity);

        $this->evictSecondLevelCache($entity, $class);
    }

    private function updateSecondLevelCache(object $entity, string $class): void
    {
        if ($this->secondLevelCache === null) {
            return;
        }

        $mapper = $this->registry->get($class);
        $pkCol = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp = $pkColDef instanceof ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $id = $mapper->getProperty($entity, $pkProp);

        if ($id === null) {
            return;
        }

        $data = $this->hydrator->extract($entity, $class);
        $this->secondLevelCache->put($class, $id, $data);
    }

    private function evictSecondLevelCache(object $entity, string $class): void
    {
        if ($this->secondLevelCache === null) {
            return;
        }

        $mapper = $this->registry->get($class);
        $pkCol = $mapper->getPrimaryKey();
        $pkColDef = $mapper->getColumnByName($pkCol);
        $pkProp = $pkColDef instanceof ColumnDefinition ? $pkColDef->getProperty() : $pkCol;
        $id = $mapper->getProperty($entity, $pkProp);

        if ($id === null) {
            return;
        }

        $this->secondLevelCache->evict($class, $id);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

            random_int(0, 0xffff),
            random_int(0, 0xffff),

            random_int(0, 0xffff),

            (random_int(0, 0x0fff) | 0x4000),

            (random_int(0, 0x3fff) | 0x8000),

            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    private function pushManagedEntity(string $oid, object $entity): void
    {
        $class = $this->entityClasses[$oid];
        $mapper = $this->registry->get($class);
        $snapshot = $this->snapshots[$entity] ?? null;

        $diff = [];
        $originalValues = [];

        if ($snapshot === null) {
            $diff = $this->hydrator->extract($entity, $class);
            foreach (array_keys($diff) as $colName) {
                $originalValues[$colName] = null;
            }
        } else {
            foreach ($mapper->getColumns() as $col) {
                if ($col->isPrimary() || $col->isVirtual() || $col->isGenerated()) {
                    continue;
                }
                $prop = $col->getProperty();
                $newVal = $entity->$prop ?? null;
                $oldVal = $snapshot->$prop ?? null;
                if ($newVal !== $oldVal) {
                    $diff[$col->getColumn()] = $newVal;
                    $originalValues[$col->getColumn()] = $oldVal;
                }
            }
        }

        if ($diff === []) {
            return;
        }

        $changeSet = new ChangeSet($entity, $class, $diff, $originalValues);
        $this->lifecycleInvoker->invoke($entity, BeforeUpdate::class);
        $this->eventDispatcher->dispatch('preUpdate', $entity, $changeSet->getChanges());
        $this->executeUpdate($oid, $changeSet);
    }

    private function pushDeletedEntity(string $oid, object $entity): void
    {
        $deleteSeen = [];
        $this->cascadeRemove($entity, $deleteSeen);
        $this->executeDelete($oid, $entity);
        unset($this->deleted[$oid]);

        foreach (array_keys($this->deleted) as $delOid) {
            $delOid = (string) $delOid;
            if (isset($this->deleted[$delOid])) {
                $this->executeDelete($delOid, $this->deleted[$delOid]);
                unset($this->deleted[$delOid]);
            }
        }
        $this->prePersistFired = [];
    }

    private function pushNewEntity(string $oid, object $entity): void
    {
        $newOidsBefore = array_fill_keys(array_map('strval', array_keys($this->new)), true);

        $seen = [];
        $this->cascadePersist($entity, $seen);

        foreach (array_keys($this->new) as $newOid) {
            $newOid = (string) $newOid;
            if ($newOid !== $oid && !isset($newOidsBefore[$newOid]) && isset($this->new[$newOid])) {
                $this->executeInsert($newOid, $this->new[$newOid]);
            }
        }

        if (isset($this->new[$oid])) {
            $this->executeInsert($oid, $this->new[$oid]);
        }
    }

    private function cascadePersist(object $entity, array &$seen): void
    {
        $oid = spl_object_id($entity);

        if (isset($seen[$oid])) {
            return;
        }

        $seen[$oid] = true;

        $class  = $entity::class;

        if (!$this->registry->has($class)) {
            return;
        }

        $mapper = $this->registry->get($class);

        foreach ($mapper->getRelations() as $relation) {
            if (!$relation->hasCascade(CascadeType::Persist)) {
                continue;
            }

            $propertyName = $relation->getProperty();

            try {
                $value = $mapper->getProperty($entity, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $related = [];

            if (is_object($value)) {
                $related = [$value];
            } elseif (is_array($value) || $value instanceof \Traversable) {
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $related[] = $item;
                    }
                }
            }

            foreach ($related as $relatedEntity) {
                $relatedOid = (string) spl_object_id($relatedEntity);

                if (!isset($this->new[$relatedOid]) && !isset($this->managed[$relatedOid])) {

                    $this->add($relatedEntity);
                }

                $this->cascadePersist($relatedEntity, $seen);
            }
        }
    }

    private function cascadeRemove(object $entity, array &$seen): void
    {
        $oid = spl_object_id($entity);

        if (isset($seen[$oid])) {
            return;
        }

        $seen[$oid] = true;

        $class  = $this->entityClasses[(string) $oid] ?? $entity::class;

        if (!$this->registry->has($class)) {
            return;
        }

        $mapper = $this->registry->get($class);

        foreach ($mapper->getRelations() as $relation) {
            if (!$relation->hasCascade(CascadeType::Remove)) {
                continue;
            }

            $propertyName = $relation->getProperty();

            try {
                $value = $mapper->getProperty($entity, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $related = [];

            if (is_object($value)) {
                $related = [$value];
            } elseif (is_array($value) || $value instanceof \Traversable) {
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $related[] = $item;
                    }
                }
            }

            foreach ($related as $relatedEntity) {
                $this->cascadeRemove($relatedEntity, $seen);
                $this->delete($relatedEntity);
            }
        }
    }

    private function safeClone(object $entity): ?object
    {
        try {
            return clone $entity;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildTypesForColumns(array $colDefs, array $columnNames): array
    {
        $typeMap = [];
        foreach ($colDefs as $col) {
            $typeMap[$col->getColumn()] = $col->getType();
        }

        $types = [];
        foreach ($columnNames as $name) {
            $types[] = $typeMap[$name] ?? 'string';
        }

        return $types;
    }
}
