<?php

declare(strict_types=1);

namespace Weaver\ORM\Testing;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Manager\EntityWorkspace;

abstract class EntityFactory
{
    private int $count = 1;
    private array $overrides = [];
    private int $sequenceIndex = 0;

    public static function new(): static
    {
        return new static();
    }

    abstract protected function definition(): array;

    public function set(array|string $key, mixed $value = null): static
    {
        $clone = clone $this;

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $clone->overrides[$k] = $v;
            }
        } else {
            $clone->overrides[$key] = $value;
        }

        return $clone;
    }

    public function count(int $n): static
    {
        $clone = clone $this;
        $clone->count = $n;
        return $clone;
    }

    public function state(array $overrides): static
    {
        return $this->set($overrides);
    }

    public function make(): object
    {
        return $this->buildEntity($this->resolveAttributes());
    }

    public function makeMany(): EntityCollection
    {

        $baseDefinition = $this->definition();
        $items          = [];

        for ($i = 0; $i < $this->count; $i++) {
            $items[] = $this->buildEntity($this->resolveAttributesFromDefinition($baseDefinition));
        }

        return new EntityCollection($items);
    }

    public function create(EntityWorkspace $workspace): object
    {
        $entity = $this->buildEntity($this->resolveAttributes());
        $workspace->getUnitOfWork()->add($entity);
        $workspace->push($entity);
        return $entity;
    }

    public function createMany(EntityWorkspace $workspace): EntityCollection
    {

        $baseDefinition = $this->definition();
        $items          = [];

        for ($i = 0; $i < $this->count; $i++) {
            $entity  = $this->buildEntity($this->resolveAttributesFromDefinition($baseDefinition));
            $workspace->getUnitOfWork()->add($entity);
            $items[] = $entity;
        }

        $workspace->push();
        return new EntityCollection($items);
    }

    protected function sequence(mixed ...$values): \Closure
    {
        $index = 0;
        return static function () use ($values, &$index): mixed {
            $value = $values[$index % count($values)];
            $index++;
            return $value;
        };
    }

    private function resolveAttributes(): array
    {
        return $this->resolveAttributesFromDefinition($this->definition());
    }

    private function resolveAttributesFromDefinition(array $definition): array
    {
        $attributes = array_merge($definition, $this->overrides);

        foreach ($attributes as $key => $value) {
            if ($value instanceof \Closure) {
                $attributes[$key] = $value();
            }
        }

        return $attributes;
    }

    private function buildEntity(array $attributes): object
    {
        $entityClass = $this->entityClass();
        $entity      = new $entityClass();

        foreach ($attributes as $property => $value) {
            $entity->$property = $value;
        }

        return $entity;
    }

    protected function entityClass(): string
    {

        $class = static::class;
        if (str_ends_with($class, 'Factory')) {
            return substr($class, 0, -7);
        }
        throw new \LogicException(
            sprintf(
                'Cannot derive entity class from factory %s. Override entityClass() or follow the <Entity>Factory naming convention.',
                $class,
            )
        );
    }
}
