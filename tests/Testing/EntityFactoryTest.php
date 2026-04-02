<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Testing;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Schema\SchemaGenerator;
use Weaver\ORM\Testing\EntityFactory;
use Weaver\ORM\Testing\Faker;

class FactoryTestUser
{
    public ?int $id      = null;
    public string $name  = '';
    public string $email = '';
    public bool $active  = false;
    public int $age      = 0;
}

class FactoryTestUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return FactoryTestUser::class; }
    public function getTableName(): string   { return 'factory_test_users'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 100),
            new ColumnDefinition('email',  'email',  'string',  length: 180),
            new ColumnDefinition('active', 'active', 'boolean'),
            new ColumnDefinition('age',    'age',    'integer'),
        ];
    }
}

class FactoryTestUserFactory extends EntityFactory
{
    protected function entityClass(): string
    {
        return FactoryTestUser::class;
    }

    protected function definition(): array
    {
        return [
            'name'   => Faker::name(),
            'email'  => Faker::email(),
            'active' => false,
            'age'    => 25,
        ];
    }
}

final class EntityFactoryTest extends TestCase
{




    private function makeEntityManager(): EntityWorkspace
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $registry = new MapperRegistry();
        $registry->register(new FactoryTestUserMapper());

        $hydrator   = new EntityHydrator($registry, $connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($connection, $registry, $hydrator, $dispatcher, $resolver);

        $generator = new SchemaGenerator($registry, $connection->getDatabasePlatform());
        foreach ($generator->generateSql() as $sql) {
            $connection->executeStatement($sql);
        }

        return new EntityWorkspace('default', $connection, $registry, $uow);
    }





    public function test_make_returns_entity_with_default_values(): void
    {
        $user = FactoryTestUserFactory::new()->make();

        self::assertInstanceOf(FactoryTestUser::class, $user);
        self::assertNotEmpty($user->name,  'name should be set from definition');
        self::assertNotEmpty($user->email, 'email should be set from definition');
        self::assertSame(false, $user->active, 'active should default to false');
        self::assertSame(25, $user->age,   'age should default to 25');
        self::assertNull($user->id,        'id should not be set when not persisted');
    }





    public function test_set_overrides_specific_attribute(): void
    {
        $user = FactoryTestUserFactory::new()->set('name', 'Alice')->make();

        self::assertSame('Alice', $user->name);
        self::assertNotEmpty($user->email, 'other attributes should remain from definition');
    }





    public function test_count_and_make_many_returns_collection_of_n_entities(): void
    {
        $collection = FactoryTestUserFactory::new()->count(3)->makeMany();

        self::assertInstanceOf(EntityCollection::class, $collection);
        self::assertCount(3, $collection);

        foreach ($collection as $user) {
            self::assertInstanceOf(FactoryTestUser::class, $user);
            self::assertNull($user->id, 'entities should not be persisted');
        }
    }





    public function test_create_persists_entity_and_returns_it_with_id(): void
    {
        $workspace   = $this->makeEntityManager();
        $user = FactoryTestUserFactory::new()
            ->set('name', 'Bob')
            ->create($workspace);

        self::assertInstanceOf(FactoryTestUser::class, $user);
        self::assertNotNull($user->id, 'id should be set after persist + flush');
        self::assertIsInt($user->id);
        self::assertGreaterThan(0, $user->id);
        self::assertSame('Bob', $user->name);
    }





    public function test_count_and_create_many_persists_n_entities(): void
    {
        $workspace         = $this->makeEntityManager();
        $collection = FactoryTestUserFactory::new()->count(5)->createMany($workspace);

        self::assertInstanceOf(EntityCollection::class, $collection);
        self::assertCount(5, $collection);

        $ids = [];
        foreach ($collection as $user) {
            self::assertInstanceOf(FactoryTestUser::class, $user);
            self::assertNotNull($user->id, 'every entity must have an id after createMany');
            self::assertGreaterThan(0, $user->id);
            $ids[] = $user->id;
        }


        self::assertCount(5, array_unique($ids), 'all created entities must have unique IDs');


        $count = (int) $workspace->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM factory_test_users');

        self::assertSame(5, $count);
    }





    public function test_state_applies_named_overrides(): void
    {
        $user = FactoryTestUserFactory::new()
            ->state(['active' => true, 'age' => 99])
            ->make();

        self::assertTrue($user->active, 'state override should set active to true');
        self::assertSame(99, $user->age, 'state override should set age to 99');
        self::assertNotEmpty($user->name,  'definition defaults should still be applied');
    }





    public function test_sequence_cycles_through_values(): void
    {

        $factory = new class extends EntityFactory {
            protected function entityClass(): string { return FactoryTestUser::class; }

            protected function definition(): array
            {
                return [
                    'name'   => $this->sequence('Alice', 'Bob', 'Carol'),
                    'email'  => Faker::email(),
                    'active' => false,
                    'age'    => 0,
                ];
            }
        };

        $collection = $factory->count(5)->makeMany();

        $names = $collection->pluck('name');


        self::assertSame('Alice', $names[0]);
        self::assertSame('Bob',   $names[1]);
        self::assertSame('Carol', $names[2]);
        self::assertSame('Alice', $names[3]);
        self::assertSame('Bob',   $names[4]);
    }





    public function test_set_is_immutable_original_factory_unmodified(): void
    {
        $original = FactoryTestUserFactory::new();
        $modified = $original->set('name', 'Modified');

        $userFromOriginal = $original->make();
        $userFromModified = $modified->make();

        self::assertNotSame('Modified', $userFromOriginal->name,
            'Original factory must not be affected by set() on the clone');
        self::assertSame('Modified', $userFromModified->name,
            'Modified factory must produce entity with overridden name');
    }





    public function test_set_accepts_array_of_overrides(): void
    {
        $user = FactoryTestUserFactory::new()
            ->set(['name' => 'Charlie', 'age' => 42, 'active' => true])
            ->make();

        self::assertSame('Charlie', $user->name);
        self::assertSame(42, $user->age);
        self::assertTrue($user->active);
    }





    public function test_count_is_immutable_original_factory_unmodified(): void
    {
        $original  = FactoryTestUserFactory::new();
        $withCount = $original->count(5);

        $single = $original->makeMany();
        $five   = $withCount->makeMany();

        self::assertCount(1, $single, 'original factory should still produce 1 entity');
        self::assertCount(5, $five,   'cloned factory with count(5) should produce 5 entities');
    }
}
