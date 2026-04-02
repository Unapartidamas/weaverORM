<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Manager;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Manager\EntityWorkspace;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class IsDirtyPerson
{
    public ?int $id      = null;
    public string $name  = '';
    public string $email = '';
    public string $role  = 'user';
}

class IsDirtyPersonMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return IsDirtyPerson::class;
    }

    public function getTableName(): string
    {
        return 'isdirty_persons';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',  'name',  'string',  length: 100),
            new ColumnDefinition('email', 'email', 'string',  length: 180),
            new ColumnDefinition('role',  'role',  'string',  length: 20),
        ];
    }
}

final class IsDirtyTest extends TestCase
{
    private Connection $connection;
    private EntityWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE isdirty_persons (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                name  TEXT    NOT NULL DEFAULT \'\',
                email TEXT    NOT NULL DEFAULT \'\',
                role  TEXT    NOT NULL DEFAULT \'user\'
            )'
        );

        $registry = new MapperRegistry();
        $registry->register(new IsDirtyPersonMapper());

        $hydrator   = new EntityHydrator($registry, $this->connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $unitOfWork = new UnitOfWork($this->connection, $registry, $hydrator, $dispatcher, $resolver);

        $this->workspace = new EntityWorkspace('default', $this->connection, $registry, $unitOfWork);
    }




    public function test_new_entity_is_new_before_flush(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);

        self::assertTrue($this->workspace->isNew($person));
        self::assertFalse($this->workspace->isManaged($person));
        self::assertFalse($this->workspace->isDirty($person));
        self::assertFalse($this->workspace->isDeleted($person));
    }




    public function test_after_flush_entity_is_managed_not_new(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();

        self::assertFalse($this->workspace->isNew($person));
        self::assertTrue($this->workspace->isManaged($person));
        self::assertFalse($this->workspace->isDirty($person));
    }




    public function test_modified_managed_entity_is_dirty(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();


        $person->name = 'Alice Updated';

        self::assertTrue($this->workspace->isDirty($person));
    }




    public function test_get_changes_returns_old_and_new_values(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';
        $person->role  = 'user';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();

        $person->name = 'Alice Updated';
        $person->role = 'admin';

        $changes = $this->workspace->getChanges($person);

        self::assertArrayHasKey('name', $changes);
        self::assertSame('Alice', $changes['name']['old']);
        self::assertSame('Alice Updated', $changes['name']['new']);

        self::assertArrayHasKey('role', $changes);
        self::assertSame('user', $changes['role']['old']);
        self::assertSame('admin', $changes['role']['new']);
    }




    public function test_get_dirty_properties_lists_changed_properties(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();

        $person->name = 'Alice Changed';

        $dirtyProps = $this->workspace->getDirtyProperties($person);

        self::assertContains('name', $dirtyProps);
        self::assertNotContains('email', $dirtyProps);
    }




    public function test_get_original_value_returns_snapshot_value(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();

        $person->name = 'Alice Modified';

        self::assertSame('Alice', $this->workspace->getOriginalValue($person, 'name'));
    }




    public function test_removed_entity_is_deleted(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();

        $this->workspace->getUnitOfWork()->delete($person);

        self::assertTrue($this->workspace->isDeleted($person));
        self::assertFalse($this->workspace->isManaged($person));
        self::assertFalse($this->workspace->isNew($person));
    }




    public function test_unmodified_managed_entity_is_not_dirty(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();


        self::assertFalse($this->workspace->isDirty($person));
        self::assertSame([], $this->workspace->getChanges($person));
        self::assertSame([], $this->workspace->getDirtyProperties($person));
    }




    public function test_detached_entity_is_not_managed(): void
    {
        $person        = new IsDirtyPerson();
        $person->name  = 'Alice';
        $person->email = 'alice@example.com';

        $this->workspace->getUnitOfWork()->add($person);
        $this->workspace->push();


        self::assertTrue($this->workspace->isManaged($person));

        $this->workspace->getUnitOfWork()->untrack($person);

        self::assertFalse($this->workspace->isManaged($person));
        self::assertFalse($this->workspace->isNew($person));
        self::assertFalse($this->workspace->isDeleted($person));
        self::assertFalse($this->workspace->isDirty($person));
    }
}
