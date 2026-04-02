<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\InheritanceMapping;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class JiAnimal
{
    public ?int $id   = null;
    public string $name = '';
    public string $type = '';
}

class JiDog extends JiAnimal
{
    public string $breed = '';
}

class JiAnimalMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return JiAnimal::class;
    }

    public function getTableName(): string
    {
        return 'ji_animals';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 255),
            new ColumnDefinition('type', 'type', 'string',  length: 50),
        ];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        return new InheritanceMapping(
            type:                'JOINED',
            discriminatorColumn: 'type',
            discriminatorType:   'string',
            discriminatorMap:    ['dog' => JiDog::class],
        );
    }
}

class JiDogMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return JiDog::class;
    }



    public function getTableName(): string
    {
        return 'ji_animals';
    }



    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',  'name',  'string',  length: 255),
            new ColumnDefinition('type',  'type',  'string',  length: 50),
            new ColumnDefinition('breed', 'breed', 'string',  length: 100),
        ];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        return new InheritanceMapping(
            type:                'JOINED',
            discriminatorColumn: 'type',
            discriminatorType:   'string',
            discriminatorMap:    ['dog' => JiDog::class],
        );
    }

    public function getInheritanceJoinTable(): ?string
    {
        return 'ji_dogs';
    }

    public function getInheritanceJoinKey(): string
    {
        return 'id';
    }



    public function getOwnColumns(): array
    {
        return [
            new ColumnDefinition('breed', 'breed', 'string', length: 100),
        ];
    }
}

class JiCat
{
    public ?int $id   = null;
    public string $name = '';
}

class JiCatMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return JiCat::class;
    }

    public function getTableName(): string
    {
        return 'ji_cats';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 255),
        ];
    }
}

final class JoinedInheritancePersistenceTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private LifecycleEventDispatcher $dispatcher;
    private InsertOrderResolver $resolver;
    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);


        $this->connection->executeStatement(
            'CREATE TABLE ji_animals (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\',
                type TEXT    NOT NULL DEFAULT \'\'
            )'
        );


        $this->connection->executeStatement(
            'CREATE TABLE ji_dogs (
                id    INTEGER PRIMARY KEY,
                breed TEXT NOT NULL DEFAULT \'\'
            )'
        );


        $this->connection->executeStatement(
            'CREATE TABLE ji_cats (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new JiAnimalMapper());
        $this->registry->register(new JiDogMapper());
        $this->registry->register(new JiCatMapper());

        $this->hydrator   = new EntityHydrator($this->registry, $this->connection);
        $this->dispatcher = new LifecycleEventDispatcher();
        $this->resolver   = new InsertOrderResolver($this->registry);
        $this->uow        = new UnitOfWork(
            $this->connection,
            $this->registry,
            $this->hydrator,
            $this->dispatcher,
            $this->resolver,
        );
    }





    public function test_insert_dog_writes_to_both_tables(): void
    {
        $dog        = new JiDog();
        $dog->name  = 'Rex';
        $dog->type  = 'dog';
        $dog->breed = 'Labrador';

        $this->uow->add($dog);
        $this->uow->push();

        self::assertNotNull($dog->id, 'Auto-increment ID must be assigned');


        $animalRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_animals WHERE id = ?',
            [$dog->id],
        );
        self::assertNotFalse($animalRow, 'Row must exist in ji_animals');
        self::assertSame('Rex', $animalRow['name']);
        self::assertSame('dog', $animalRow['type']);


        $dogRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_dogs WHERE id = ?',
            [$dog->id],
        );
        self::assertNotFalse($dogRow, 'Row must exist in ji_dogs');
        self::assertSame('Labrador', $dogRow['breed']);
    }





    public function test_update_dog_updates_both_tables(): void
    {
        $dog        = new JiDog();
        $dog->name  = 'Buddy';
        $dog->type  = 'dog';
        $dog->breed = 'Poodle';

        $this->uow->add($dog);
        $this->uow->push();

        $id = $dog->id;


        $dog->name  = 'Buddy Updated';
        $dog->breed = 'Golden Retriever';

        $this->uow->push();


        $animalRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_animals WHERE id = ?',
            [$id],
        );
        self::assertNotFalse($animalRow);
        self::assertSame('Buddy Updated', $animalRow['name']);


        $dogRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_dogs WHERE id = ?',
            [$id],
        );
        self::assertNotFalse($dogRow);
        self::assertSame('Golden Retriever', $dogRow['breed']);
    }





    public function test_delete_dog_removes_from_both_tables(): void
    {
        $dog        = new JiDog();
        $dog->name  = 'Max';
        $dog->type  = 'dog';
        $dog->breed = 'Beagle';

        $this->uow->add($dog);
        $this->uow->push();

        $id = $dog->id;

        $this->uow->delete($dog);
        $this->uow->push();

        $animalRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_animals WHERE id = ?',
            [$id],
        );
        self::assertFalse($animalRow, 'Row must be removed from ji_animals');

        $dogRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_dogs WHERE id = ?',
            [$id],
        );
        self::assertFalse($dogRow, 'Row must be removed from ji_dogs');
    }





    public function test_non_joined_entity_is_unaffected(): void
    {
        $cat       = new JiCat();
        $cat->name = 'Whiskers';

        $this->uow->add($cat);
        $this->uow->push();

        self::assertNotNull($cat->id);

        $cat->name = 'Whiskers Updated';
        $this->uow->push();

        $catRow = $this->connection->fetchAssociative(
            'SELECT * FROM ji_cats WHERE id = ?',
            [$cat->id],
        );
        self::assertNotFalse($catRow);
        self::assertSame('Whiskers Updated', $catRow['name']);


        $catCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ji_cats');
        self::assertSame(1, $catCount);
    }
}
