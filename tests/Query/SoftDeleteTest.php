<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class SoftPost
{
    public ?int $id                         = null;
    public string $title                     = '';
    public ?\DateTimeImmutable $deletedAt    = null;
}

class SoftPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return SoftPost::class;
    }

    public function getTableName(): string
    {
        return 'soft_posts';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',         'id',        'integer',            primary: true, autoIncrement: true),
            new ColumnDefinition('title',      'title',     'string',             length: 255),
            new ColumnDefinition('deleted_at', 'deletedAt', 'datetime_immutable', nullable: true),
        ];
    }
}

final class SoftDeleteTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private SoftPostMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE soft_posts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT    NOT NULL DEFAULT \'\',
                deleted_at TEXT    NULL
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new SoftPostMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            SoftPost::class,
            $this->mapper,
            $this->hydrator,
        );
    }


    private function seedMixed(): void
    {
        $this->connection->insert('soft_posts', ['title' => 'Live Post',    'deleted_at' => null]);
        $this->connection->insert('soft_posts', ['title' => 'Deleted Post', 'deleted_at' => '2024-01-01 00:00:00']);
    }



    public function test_soft_deleted_rows_excluded_by_default(): void
    {
        $this->seedMixed();

        $results = $this->makeQb()->get();

        self::assertCount(1, $results);
        self::assertSame('Live Post', $results->first()->title);
    }

    public function test_with_trashed_includes_soft_deleted(): void
    {
        $this->seedMixed();

        $results = $this->makeQb()->withTrashed()->get();

        self::assertCount(2, $results);
    }

    public function test_only_trashed_returns_only_deleted(): void
    {
        $this->seedMixed();

        $results = $this->makeQb()->onlyTrashed()->get();

        self::assertCount(1, $results);
        self::assertSame('Deleted Post', $results->first()->title);
    }
}
