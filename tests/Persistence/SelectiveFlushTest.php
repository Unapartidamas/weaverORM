<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class SelectiveArticle
{
    public ?int $id    = null;
    public string $title = '';
    public int $views  = 0;
}

class SelectiveArticleMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return SelectiveArticle::class;
    }

    public function getTableName(): string
    {
        return 'selective_articles';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
            new ColumnDefinition('views', 'views', 'integer'),
        ];
    }
}

final class SelectiveFlushTest extends TestCase
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
            'CREATE TABLE selective_articles (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\',
                views INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry   = new MapperRegistry();
        $this->registry->register(new SelectiveArticleMapper());

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





    public function test_selective_flush_inserts_only_the_given_new_entity(): void
    {
        $a        = new SelectiveArticle();
        $a->title = 'Flush Me';
        $a->views = 1;

        $b        = new SelectiveArticle();
        $b->title = 'Do Not Flush Me';
        $b->views = 2;

        $this->uow->add($a);
        $this->uow->add($b);


        $this->uow->push($a);


        self::assertNotNull($a->id, 'ID must be set on the selectively-flushed entity');

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM selective_articles');
        self::assertSame(1, $count, 'Only one row should exist; $b must NOT have been inserted');

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM selective_articles WHERE id = ?',
            [$a->id],
        );
        self::assertNotFalse($row);
        self::assertSame('Flush Me', $row['title']);


        self::assertTrue($this->uow->isTracked($b), '$b must still be tracked');
        self::assertNull($b->id, '$b must still have no ID');
    }





    public function test_selective_flush_updates_only_the_given_dirty_entity(): void
    {

        $a        = new SelectiveArticle();
        $a->title = 'Alice';
        $a->views = 10;

        $b        = new SelectiveArticle();
        $b->title = 'Bob';
        $b->views = 20;

        $this->uow->add($a);
        $this->uow->add($b);
        $this->uow->push();


        $a->title = 'Alice Updated';
        $b->title = 'Bob Updated';


        $this->uow->push($a);

        $rowA = $this->connection->fetchAssociative(
            'SELECT * FROM selective_articles WHERE id = ?',
            [$a->id],
        );
        self::assertNotFalse($rowA);
        self::assertSame('Alice Updated', $rowA['title'], '$a must be updated in DB');

        $rowB = $this->connection->fetchAssociative(
            'SELECT * FROM selective_articles WHERE id = ?',
            [$b->id],
        );
        self::assertNotFalse($rowB);
        self::assertSame('Bob', $rowB['title'], '$b must NOT be updated in DB');
    }





    public function test_selective_flush_of_clean_entity_does_nothing(): void
    {
        $a        = new SelectiveArticle();
        $a->title = 'Stable';
        $a->views = 5;

        $this->uow->add($a);
        $this->uow->push();


        $rowBefore = $this->connection->fetchAssociative(
            'SELECT * FROM selective_articles WHERE id = ?',
            [$a->id],
        );


        $this->uow->push($a);

        $rowAfter = $this->connection->fetchAssociative(
            'SELECT * FROM selective_articles WHERE id = ?',
            [$a->id],
        );

        self::assertNotFalse($rowAfter);
        self::assertSame($rowBefore['title'], $rowAfter['title'], 'Title must not change');
        self::assertSame($rowBefore['views'], $rowAfter['views'], 'Views must not change');
    }





    public function test_flush_with_no_argument_flushes_all(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a        = new SelectiveArticle();
            $a->title = "Bulk {$i}";
            $this->uow->add($a);
        }


        $this->uow->push();

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM selective_articles');
        self::assertSame(3, $count, 'All three entities must be inserted by a full flush');
    }
}
