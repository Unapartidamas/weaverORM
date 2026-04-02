<?php

declare(strict_types=1);

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;

if (!function_exists('weaver')) {
    function weaver(?string $connection = null): EntityWorkspace
    {
        return $connection !== null
            ? WeaverService::workspace($connection)
            : WeaverService::workspace();
    }
}

if (!function_exists('weaver_query')) {
    function weaver_query(string $entityClass): EntityQueryBuilder
    {
        $workspace = WeaverService::workspace();
        $mapper = $workspace->getMapperRegistry()->get($entityClass);
        $connection = $workspace->getConnection();
        $hydrator = new EntityHydrator($workspace->getMapperRegistry(), $connection);

        return new EntityQueryBuilder(
            $connection,
            $entityClass,
            $mapper,
            $hydrator,
        );
    }
}

if (!function_exists('weaver_registry')) {
    function weaver_registry(): MapperRegistry
    {
        return WeaverService::mapperRegistry();
    }
}
