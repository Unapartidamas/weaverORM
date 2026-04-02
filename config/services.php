<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Weaver\ORM\Cache\CacheConfiguration;
use Weaver\ORM\Cache\QueryResultCache;
use Weaver\ORM\Cache\SecondLevelCache;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Hydration\PivotHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Relation\RelationLoader;
use Weaver\ORM\Transaction\TransactionManager;
use Weaver\ORM\PyroSQL\PyroSqlDriver;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();


    $services->set(MapperRegistry::class)
        ->public();


    $services->set(EntityHydrator::class)->public();
    $services->set(PivotHydrator::class)->public();


    $services->set(LifecycleEventDispatcher::class)->public();


    $services->set(InsertOrderResolver::class)->public();


    $services->set(UnitOfWork::class)
        ->public()
        ->tag('kernel.reset', ['method' => 'reset']);


    $services->set(RelationLoader::class)->public();


    $services->set(TransactionManager::class)->public();


    $services->set(PyroSqlDriver::class)->public();


    $services->set(CacheConfiguration::class)->public();

    $services->set(SecondLevelCache::class)->public();

    $services->set(QueryResultCache::class)->public();
};
