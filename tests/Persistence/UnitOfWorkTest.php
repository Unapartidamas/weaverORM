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

class Article
{
    public ?int $id    = null;
    public string $title = '';
    public int $views  = 0;
}

class ArticleMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Article::class;
    }

    public function getTableName(): string
    {
        return 'articles';
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

final class UnitOfWorkTest extends TestCase
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
            'CREATE TABLE articles (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\',
                views INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->registry   = new MapperRegistry();
        $this->registry->register(new ArticleMapper());

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



    public function test_persist_and_flush_inserts_row(): void
    {
        $article        = new Article();
        $article->title = 'Hello World';
        $article->views = 10;

        $this->uow->add($article);
        $this->uow->push();

        self::assertNotNull($article->id, 'Auto-increment ID must be set after flush');
        self::assertIsInt($article->id);

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM articles WHERE id = ?',
            [$article->id],
        );

        self::assertNotFalse($row);
        self::assertSame('Hello World', $row['title']);
        self::assertSame(10, (int) $row['views']);
    }

    public function test_flush_updates_changed_fields(): void
    {
        $article        = new Article();
        $article->title = 'Original';
        $article->views = 0;

        $this->uow->add($article);
        $this->uow->push();

        $article->title = 'Updated';
        $article->views = 99;

        $this->uow->add($article);
        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM articles WHERE id = ?',
            [$article->id],
        );

        self::assertNotFalse($row);
        self::assertSame('Updated', $row['title']);
        self::assertSame(99, (int) $row['views']);
    }

    public function test_remove_and_flush_deletes_row(): void
    {
        $article        = new Article();
        $article->title = 'To delete';

        $this->uow->add($article);
        $this->uow->push();

        $this->uow->delete($article);
        $this->uow->push();

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM articles');
        self::assertSame(0, $count);
    }

    public function test_flush_multiple_entities_in_one_call(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $a        = new Article();
            $a->title = "Article {$i}";
            $this->uow->add($a);
        }

        $this->uow->push();

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM articles');
        self::assertSame(3, $count);
    }

    public function test_no_update_when_nothing_changed(): void
    {
        $article        = new Article();
        $article->title = 'Stable';
        $article->views = 5;

        $this->uow->add($article);
        $this->uow->push();



        $this->uow->add($article);
        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM articles WHERE id = ?',
            [$article->id],
        );

        self::assertNotFalse($row);
        self::assertSame('Stable', $row['title']);
        self::assertSame(5, (int) $row['views']);
    }

    public function test_contains_returns_true_after_persist(): void
    {
        $article = new Article();
        $article->title = 'Test';

        self::assertFalse($this->uow->isTracked($article), 'Before persist: not contained');

        $this->uow->add($article);

        self::assertTrue($this->uow->isTracked($article), 'After persist: must be contained');
    }

    public function test_clear_removes_all_tracked_entities(): void
    {
        $article = new Article();
        $article->title = 'Tracked';

        $this->uow->add($article);

        self::assertTrue($this->uow->isTracked($article));

        $this->uow->reset();

        self::assertFalse($this->uow->isTracked($article));
    }

    public function test_reset_clears_state(): void
    {
        $article = new Article();
        $article->title = 'Tracked';

        $this->uow->add($article);

        self::assertTrue($this->uow->isTracked($article));

        $this->uow->reset();

        self::assertFalse($this->uow->isTracked($article));
    }

    public function test_bulk_entities_get_sequential_ids(): void
    {
        $articles = [];

        for ($i = 1; $i <= 5; $i++) {
            $a        = new Article();
            $a->title = "Bulk {$i}";
            $this->uow->add($a);
            $articles[] = $a;
        }

        $this->uow->push();

        $ids = array_map(static fn (Article $a): ?int => $a->id, $articles);


        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_bulk_update_issues_single_query_for_same_dirty_column_set(): void
    {

        $articles = [];
        for ($i = 1; $i <= 3; $i++) {
            $a        = new Article();
            $a->title = "Original {$i}";
            $a->views = $i;
            $this->uow->add($a);
            $articles[] = $a;
        }
        $this->uow->push();


        foreach ($articles as $idx => $a) {
            $a->title = "Updated {$idx}";
            $a->views = ($idx + 1) * 10;
        }

        $this->uow->push();


        foreach ($articles as $idx => $a) {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM articles WHERE id = ?',
                [$a->id],
            );
            self::assertNotFalse($row, "Row for article {$idx} must exist");
            self::assertSame("Updated {$idx}", $row['title']);
            self::assertSame(($idx + 1) * 10, (int) $row['views']);
        }
    }

    public function test_bulk_update_falls_back_to_individual_when_dirty_sets_differ(): void
    {

        $a1 = new Article();
        $a1->title = 'First';
        $a1->views = 1;

        $a2 = new Article();
        $a2->title = 'Second';
        $a2->views = 2;

        $this->uow->add($a1);
        $this->uow->add($a2);
        $this->uow->push();



        $a1->title = 'First Updated';
        $a2->views = 99;

        $this->uow->push();

        $row1 = $this->connection->fetchAssociative('SELECT * FROM articles WHERE id = ?', [$a1->id]);
        $row2 = $this->connection->fetchAssociative('SELECT * FROM articles WHERE id = ?', [$a2->id]);

        self::assertNotFalse($row1);
        self::assertNotFalse($row2);
        self::assertSame('First Updated', $row1['title']);
        self::assertSame(1, (int) $row1['views']);
        self::assertSame('Second', $row2['title']);
        self::assertSame(99, (int) $row2['views']);
    }
}
