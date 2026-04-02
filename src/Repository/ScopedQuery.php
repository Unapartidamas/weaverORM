<?php

declare(strict_types=1);

namespace Weaver\ORM\Repository;

use BadMethodCallException;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Query\EntityQueryBuilder;

final class ScopedQuery
{
    public function __construct(
        private readonly EntityQueryBuilder $qb,
        private readonly EntityRepository $repo,
    ) {}

    public function __call(string $method, array $args): mixed
    {
        $scopeMethod = 'scope' . ucfirst($method);

        if (method_exists($this->repo, $scopeMethod)) {

            $this->repo->$scopeMethod($this->qb, ...$args);
            return $this;
        }

        if (!method_exists($this->qb, $method)) {
            throw new BadMethodCallException(
                sprintf(
                    'Call to undefined method %s::%s() — no scope "%s" or QB method "%s" found.',
                    static::class,
                    $method,
                    $scopeMethod,
                    $method,
                ),
            );
        }

        $result = $this->qb->$method(...$args);

        if ($result instanceof EntityQueryBuilder) {
            return new self($result, $this->repo);
        }

        return $result;
    }

    public function scope(string $name): static
    {
        $scopeMethod = 'scope' . ucfirst($name);

        if (!method_exists($this->repo, $scopeMethod)) {
            throw new \BadMethodCallException(
                sprintf(
                    'Scope "%s" is not defined on %s. Expected a method named "%s".',
                    $name,
                    $this->repo::class,
                    $scopeMethod,
                ),
            );
        }

        $this->repo->$scopeMethod($this->qb);

        return $this;
    }

    public function get(array $with = []): EntityCollection
    {
        return $this->qb->get($with);
    }

    public function first(array $with = []): ?object
    {
        return $this->qb->first($with);
    }

    public function count(?string $column = null): int
    {
        return $this->qb->count($column);
    }

    public function getQueryBuilder(): EntityQueryBuilder
    {
        return $this->qb;
    }
}
