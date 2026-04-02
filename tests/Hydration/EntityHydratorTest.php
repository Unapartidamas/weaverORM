<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Hydration;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;

class User
{
    public ?int $id                            = null;
    public string $name                        = '';
    public ?string $email                      = null;
    public bool $active                        = false;
    public ?\DateTimeImmutable $createdAt      = null;
}

class UserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return User::class;
    }

    public function getTableName(): string
    {
        return 'users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',         'id',        'integer',            primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('name',        'name',      'string',             nullable: false, length: 255),
            new ColumnDefinition('email',       'email',     'string',             nullable: true,  length: 255),
            new ColumnDefinition('active',      'active',    'boolean',            nullable: false),
            new ColumnDefinition('created_at',  'createdAt', 'datetime_immutable', nullable: true),
        ];
    }
}

final class EntityHydratorTest extends TestCase
{
    private EntityHydrator $hydrator;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->registry = new MapperRegistry();
        $this->registry->register(new UserMapper());

        $this->hydrator = new EntityHydrator($this->registry, $connection);
    }



    public function test_hydrates_simple_row_into_entity(): void
    {
        $row = [
            'id'         => 1,
            'name'       => 'Alice',
            'email'      => 'alice@example.com',
            'active'     => 1,
            'created_at' => null,
        ];


        $entity = $this->hydrator->hydrate(User::class, $row);

        $this->assertInstanceOf(User::class, $entity);
        $this->assertSame(1,                   $entity->id);
        $this->assertSame('Alice',             $entity->name);
        $this->assertSame('alice@example.com', $entity->email);
    }

    public function test_hydrates_integer_column(): void
    {
        $row = ['id' => '42', 'name' => 'Bob', 'email' => null, 'active' => 0, 'created_at' => null];


        $entity = $this->hydrator->hydrate(User::class, $row);

        $this->assertSame(42, $entity->id);
    }

    public function test_hydrates_boolean_column(): void
    {
        $rowTrue  = ['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => 1,  'created_at' => null];
        $rowFalse = ['id' => 2, 'name' => 'Bob',   'email' => null, 'active' => 0,  'created_at' => null];


        $trueEntity  = $this->hydrator->hydrate(User::class, $rowTrue);

        $falseEntity = $this->hydrator->hydrate(User::class, $rowFalse);

        $this->assertTrue($trueEntity->active,   'active=1 should hydrate to true.');
        $this->assertFalse($falseEntity->active, 'active=0 should hydrate to false.');
    }

    public function test_hydrates_nullable_column_as_null(): void
    {
        $row = ['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => 1, 'created_at' => null];


        $entity = $this->hydrator->hydrate(User::class, $row);

        $this->assertNull($entity->email,     '"email" should be null when the DB value is NULL.');
        $this->assertNull($entity->createdAt, '"createdAt" should be null when the DB value is NULL.');
    }

    public function test_hydrates_datetime_immutable(): void
    {
        $row = [
            'id'         => 1,
            'name'       => 'Alice',
            'email'      => null,
            'active'     => 1,
            'created_at' => '2024-01-15 12:00:00',
        ];


        $entity = $this->hydrator->hydrate(User::class, $row);

        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            $entity->createdAt,
            '"createdAt" should be converted to a DateTimeImmutable.',
        );

        $this->assertSame('2024-01-15', $entity->createdAt->format('Y-m-d'));
        $this->assertSame('12:00:00',   $entity->createdAt->format('H:i:s'));
    }

    public function test_hydrate_many_returns_entity_collection(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'email' => null, 'active' => 1, 'created_at' => null],
            ['id' => 2, 'name' => 'Bob',   'email' => null, 'active' => 0, 'created_at' => null],
            ['id' => 3, 'name' => 'Carol', 'email' => null, 'active' => 1, 'created_at' => null],
        ];

        $collection = $this->hydrator->hydrateMany(User::class, $rows);

        $this->assertInstanceOf(EntityCollection::class, $collection);
        $this->assertCount(3, $collection);
    }

    public function test_hydrate_many_empty_rows_returns_empty_collection(): void
    {
        $collection = $this->hydrator->hydrateMany(User::class, []);

        $this->assertInstanceOf(EntityCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertTrue($collection->isEmpty());
    }

    public function test_extract_returns_db_values(): void
    {
        $entity        = new User();
        $entity->id    = 1;
        $entity->name  = 'Alice';
        $entity->email = 'alice@example.com';

        $data = $this->hydrator->extract($entity, User::class);


        $this->assertArrayHasKey('name',  $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertSame('Alice',             $data['name']);
        $this->assertSame('alice@example.com', $data['email']);
    }

    public function test_extract_skips_auto_increment_pk(): void
    {
        $entity       = new User();
        $entity->id   = 7;
        $entity->name = 'Bob';

        $data = $this->hydrator->extract($entity, User::class);


        $this->assertArrayNotHasKey(
            'id',
            $data,
            'Auto-increment PK should not appear in extract() output.',
        );
    }

    public function test_extract_changeset_returns_only_changed_columns(): void
    {
        $entity        = new User();
        $entity->id    = 1;
        $entity->name  = 'Alice';
        $entity->email = 'alice@example.com';
        $entity->active = true;


        $snapshot = $this->hydrator->extract($entity, User::class);


        $entity->name = 'Alicia';

        $changeset = $this->hydrator->extractChangeset($entity, User::class, $snapshot);

        $this->assertArrayHasKey('name', $changeset, 'Changed column "name" must appear in changeset.');
        $this->assertSame('Alicia', $changeset['name']);
        $this->assertArrayNotHasKey('email',  $changeset, 'Unchanged "email" must not appear in changeset.');
        $this->assertArrayNotHasKey('active', $changeset, 'Unchanged "active" must not appear in changeset.');
    }

    public function test_extract_changeset_returns_empty_when_nothing_changed(): void
    {
        $entity        = new User();
        $entity->id    = 1;
        $entity->name  = 'Alice';
        $entity->email = 'alice@example.com';
        $entity->active = false;

        $snapshot  = $this->hydrator->extract($entity, User::class);
        $changeset = $this->hydrator->extractChangeset($entity, User::class, $snapshot);

        $this->assertSame([], $changeset, 'Changeset must be empty when no columns changed.');
    }
}
