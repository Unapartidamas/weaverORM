<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Manager;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Manager\Exception\ManagerNotFoundException;
use Weaver\ORM\Manager\ManagerRegistry;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

final class ManagerRegistryTest extends TestCase
{
    private function makeEntityManager(string $name): EntityWorkspace
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $mapperRegistry = new MapperRegistry();
        $hydrator       = new EntityHydrator($mapperRegistry, $connection);
        $dispatcher     = new LifecycleEventDispatcher();
        $resolver       = new InsertOrderResolver($mapperRegistry);
        $unitOfWork     = new UnitOfWork($connection, $mapperRegistry, $hydrator, $dispatcher, $resolver);

        return new EntityWorkspace($name, $connection, $mapperRegistry, $unitOfWork);
    }

    public function test_get_manager_returns_default_manager(): void
    {
        $default  = $this->makeEntityManager('default');
        $registry = new ManagerRegistry(['default' => $default]);

        self::assertSame($default, $registry->getManager());
    }

    public function test_get_manager_with_explicit_name_returns_named_manager(): void
    {
        $default  = $this->makeEntityManager('default');
        $secondary = $this->makeEntityManager('secondary');
        $registry  = new ManagerRegistry(['default' => $default, 'secondary' => $secondary]);

        self::assertSame($secondary, $registry->getManager('secondary'));
    }

    public function test_get_manager_with_nonexistent_name_throws_exception(): void
    {
        $registry = new ManagerRegistry(['default' => $this->makeEntityManager('default')]);

        $this->expectException(ManagerNotFoundException::class);
        $this->expectExceptionMessage("EntityWorkspace 'nonexistent' not found in ManagerRegistry.");

        $registry->getManager('nonexistent');
    }

    public function test_get_default_manager_returns_configured_default(): void
    {
        $main    = $this->makeEntityManager('main');
        $other   = $this->makeEntityManager('other');
        $registry = new ManagerRegistry(['main' => $main, 'other' => $other], 'main');

        self::assertSame($main, $registry->getDefaultManager());
    }

    public function test_has_manager_returns_true_for_existing_manager(): void
    {
        $registry = new ManagerRegistry(['default' => $this->makeEntityManager('default')]);

        self::assertTrue($registry->hasManager('default'));
    }

    public function test_has_manager_returns_false_for_missing_manager(): void
    {
        $registry = new ManagerRegistry(['default' => $this->makeEntityManager('default')]);

        self::assertFalse($registry->hasManager('missing'));
    }

    public function test_get_manager_names_returns_all_names(): void
    {
        $registry = new ManagerRegistry([
            'default'   => $this->makeEntityManager('default'),
            'secondary' => $this->makeEntityManager('secondary'),
            'reporting' => $this->makeEntityManager('reporting'),
        ]);

        self::assertSame(['default', 'secondary', 'reporting'], $registry->getManagerNames());
    }

    public function test_all_returns_full_map(): void
    {
        $default   = $this->makeEntityManager('default');
        $secondary = $this->makeEntityManager('secondary');

        $registry = new ManagerRegistry([
            'default'   => $default,
            'secondary' => $secondary,
        ]);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertSame($default, $all['default']);
        self::assertSame($secondary, $all['secondary']);
    }

    public function test_registry_with_multiple_managers_works_correctly(): void
    {
        $em1 = $this->makeEntityManager('write');
        $em2 = $this->makeEntityManager('read');
        $em3 = $this->makeEntityManager('reporting');

        $registry = new ManagerRegistry(
            ['write' => $em1, 'read' => $em2, 'reporting' => $em3],
            'write',
        );

        self::assertSame($em1, $registry->getManager());
        self::assertSame($em1, $registry->getManager('write'));
        self::assertSame($em2, $registry->getManager('read'));
        self::assertSame($em3, $registry->getManager('reporting'));
        self::assertTrue($registry->hasManager('read'));
        self::assertFalse($registry->hasManager('archive'));
        self::assertSame(['write', 'read', 'reporting'], $registry->getManagerNames());

        $all = $registry->all();
        self::assertSame($em1, $all['write']);
        self::assertSame($em2, $all['read']);
        self::assertSame($em3, $all['reporting']);
    }

    public function test_entity_manager_exposes_correct_name(): void
    {
        $workspace = $this->makeEntityManager('my-manager');
        self::assertSame('my-manager', $workspace->getName());
    }

    public function test_entity_manager_exposes_connection_mapper_registry_and_unit_of_work(): void
    {
        $connection     = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $mapperRegistry = new MapperRegistry();
        $hydrator       = new EntityHydrator($mapperRegistry, $connection);
        $dispatcher     = new LifecycleEventDispatcher();
        $resolver       = new InsertOrderResolver($mapperRegistry);
        $unitOfWork     = new UnitOfWork($connection, $mapperRegistry, $hydrator, $dispatcher, $resolver);

        $workspace = new EntityWorkspace('test', $connection, $mapperRegistry, $unitOfWork);

        self::assertSame($connection, $workspace->getConnection());
        self::assertSame($mapperRegistry, $workspace->getMapperRegistry());
        self::assertSame($unitOfWork, $workspace->getUnitOfWork());
    }

    public function test_manager_not_found_exception_message(): void
    {
        $exception = new ManagerNotFoundException('my_manager');
        self::assertSame("EntityWorkspace 'my_manager' not found in ManagerRegistry.", $exception->getMessage());
    }
}
