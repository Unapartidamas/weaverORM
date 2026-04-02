<?php

declare(strict_types=1);

namespace Weaver\ORM\Contract;

interface UnitOfWorkInterface
{
    public function add(object $entity): void;
    public function delete(object $entity): void;
    public function push(): void;
    public function reset(): void;
    public function untrack(object $entity): void;
    public function isTracked(object $entity): bool;
    public function reload(object $entity): void;
}
