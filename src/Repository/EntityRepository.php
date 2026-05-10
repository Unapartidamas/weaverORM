<?php

declare(strict_types=1);

namespace Weaver\ORM\Repository;

use BadMethodCallException;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Query\EntityQueryBuilder;

class EntityRepository
{
    public function __construct(
        protected readonly EntityWorkspace $workspace,
        protected readonly string $entityClass,
    ) {}

    public function find(int|string $id): ?object
    {
        $cached = $this->workspace->getUnitOfWork()->findInIdentityMap($this->entityClass, $id);
        if ($cached !== null) {
            return $cached;
        }

        $mapper   = $this->getMapper();
        $pkColumn = $mapper->getPrimaryKey();

        // query() returns a builder pre-wired with the UoW, so the
        // entity returned by ->first() is already tracked.
        return $this->query()
            ->where($pkColumn, $id)
            ->first();
    }

    public function findOrFail(int|string $id): object
    {
        $entity = $this->find($id);

        if ($entity === null) {
            throw EntityNotFoundException::forId($this->entityClass, $id);
        }

        return $entity;
    }

    public function findAll(): EntityCollection
    {
        return $this->query()->get();
    }

    public function findBy(
        array $criteria,
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null,
    ): EntityCollection {
        $qb = $this->query();

        foreach ($criteria as $column => $value) {
            $qb->where($column, $value);
        }

        foreach ($orderBy as $column => $direction) {
            $qb->orderBy($column, $direction);
        }

        if ($limit !== null) {
            $qb->limit($limit);
        }

        if ($offset !== null) {
            $qb->offset($offset);
        }

        return $qb->get();
    }

    public function findOneBy(array $criteria): ?object
    {
        $qb = $this->query();

        foreach ($criteria as $column => $value) {
            $qb->where($column, $value);
        }

        return $qb->first();
    }

    public function count(array $criteria = []): int
    {
        $qb = $this->query();

        foreach ($criteria as $column => $value) {
            $qb->where($column, $value);
        }

        return $qb->count();
    }

    public function query(): EntityQueryBuilder
    {
        $connection = $this->workspace->getReadConnection();
        $mapper     = $this->getMapper();
        $registry   = $this->workspace->getMapperRegistry();
        $hydrator   = new \Weaver\ORM\Hydration\EntityHydrator($registry, $connection);

        $qb = new EntityQueryBuilder(
            $connection,
            $this->entityClass,
            $mapper,
            $hydrator,
        );

        // Wire UoW tracking so entities loaded via this query become
        // managed and any subsequent property mutations are detected
        // by push().
        $qb->setUnitOfWork($this->workspace->getUnitOfWork());

        return $qb;
    }

    public function scope(string $name): ScopedQuery
    {
        $scopeMethod = 'scope' . ucfirst($name);

        if (!method_exists($this, $scopeMethod)) {
            throw new BadMethodCallException(
                sprintf(
                    'Scope "%s" is not defined on %s. Expected a method named "%s".',
                    $name,
                    static::class,
                    $scopeMethod,
                ),
            );
        }

        $qb = $this->query();
        $this->$scopeMethod($qb);

        return new ScopedQuery($qb, $this);
    }

    public function __call(string $method, array $args): ScopedQuery
    {
        $scopeMethod = 'scope' . ucfirst($method);

        if (!method_exists($this, $scopeMethod)) {
            throw new BadMethodCallException(
                sprintf(
                    'Call to undefined method %s::%s() — no scope "%s" found.',
                    static::class,
                    $method,
                    $scopeMethod,
                ),
            );
        }

        $qb = $this->query();
        $this->$scopeMethod($qb, ...$args);

        return new ScopedQuery($qb, $this);
    }

    protected function getMapper(): AbstractEntityMapper
    {

        return $this->workspace->getMapperRegistry()->get($this->entityClass);
    }
}
