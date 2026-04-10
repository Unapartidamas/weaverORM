<?php

declare(strict_types=1);

namespace Weaver\ORM\Manager;

use Weaver\ORM\Connection\ConnectionRegistry;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\Attribute\Connection;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Manager\Exception\ManagerNotFoundException;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

final class WorkspaceRegistry
{
    private array $workspaces = [];
    private array $entityConnectionMap = [];
    private string $defaultName;

    public function __construct(
        private readonly ConnectionRegistry $connectionRegistry,
        private readonly ?\Closure $workspaceFactory = null,
        string $defaultName = 'default',
        private readonly ?MapperRegistry $mapperRegistry = null,
        private readonly ?EntityHydrator $hydrator = null,
        private readonly ?LifecycleEventDispatcher $eventDispatcher = null,
        private readonly ?InsertOrderResolver $insertOrderResolver = null,
    ) {
        $this->defaultName = $defaultName;
    }

    public function getWorkspace(string $connectionName = 'default'): EntityWorkspace
    {
        if (!$this->connectionRegistry->hasConnection($connectionName)) {
            throw new ManagerNotFoundException($connectionName);
        }

        if (!isset($this->workspaces[$connectionName])) {
            $connection = $this->connectionRegistry->getConnection($connectionName);

            if ($this->workspaceFactory !== null) {
                $this->workspaces[$connectionName] = ($this->workspaceFactory)($connectionName, $connection);
            } else {
                $mapperRegistry = $this->mapperRegistry ?? throw new \LogicException(
                    'WorkspaceRegistry requires either a workspace factory Closure or a MapperRegistry instance.',
                );
                $hydrator = $this->hydrator ?? new EntityHydrator($mapperRegistry, $connection);
                $eventDispatcher = $this->eventDispatcher ?? new LifecycleEventDispatcher();
                $insertOrderResolver = $this->insertOrderResolver ?? new InsertOrderResolver($mapperRegistry);
                $unitOfWork = new UnitOfWork(
                    $connection,
                    $mapperRegistry,
                    $hydrator,
                    $eventDispatcher,
                    $insertOrderResolver,
                );
                $this->workspaces[$connectionName] = new EntityWorkspace(
                    $connectionName,
                    $connection,
                    $mapperRegistry,
                    $unitOfWork,
                );
            }
        }

        return $this->workspaces[$connectionName];
    }

    public function getDefaultWorkspace(): EntityWorkspace
    {
        return $this->getWorkspace($this->defaultName);
    }

    public function getWorkspaceForEntity(string $entityClass): EntityWorkspace
    {
        if (isset($this->entityConnectionMap[$entityClass])) {
            return $this->getWorkspace($this->entityConnectionMap[$entityClass]);
        }

        $attributes = (new \ReflectionClass($entityClass))->getAttributes(Connection::class);

        if ($attributes !== []) {
            $connectionName = $attributes[0]->newInstance()->name;
            $this->entityConnectionMap[$entityClass] = $connectionName;

            return $this->getWorkspace($connectionName);
        }

        $this->entityConnectionMap[$entityClass] = $this->defaultName;

        return $this->getDefaultWorkspace();
    }

    public function getWorkspaceNames(): array
    {
        return $this->connectionRegistry->getConnectionNames();
    }

    public function resetAll(): void
    {
        $this->workspaces = [];
        $this->entityConnectionMap = [];
    }
}
