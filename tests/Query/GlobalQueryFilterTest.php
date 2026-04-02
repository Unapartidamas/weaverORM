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
use Weaver\ORM\Query\Filter\QueryFilterInterface;
use Weaver\ORM\Query\Filter\QueryFilterRegistry;
use Weaver\ORM\Query\Filter\SoftDeleteFilter;

class Article
{
    public ?int $id           = null;
    public string $title      = '';
    public ?string $deletedAt = null;
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
            new ColumnDefinition('id',         'id',        'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title',       'title',     'string',  length: 255),
            new ColumnDefinition('deleted_at',  'deletedAt', 'string',  nullable: true),
        ];
    }
}

class ArticleNoSoftDelete
{
    public ?int $id      = null;
    public string $title = '';
}

class ArticleNoSoftDeleteMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return ArticleNoSoftDelete::class;
    }

    public function getTableName(): string
    {
        return 'articles_plain';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
        ];
    }
}

final class NonEmptyTitleFilter implements QueryFilterInterface
{
    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function apply(EntityQueryBuilder $qb): void
    {
        $qb->whereRaw("title != ''");
    }
}

final class GlobalQueryFilterTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private ArticleMapper $articleMapper;
    private ArticleNoSoftDeleteMapper $plainMapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE articles (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT    NOT NULL DEFAULT \'\',
                deleted_at TEXT    NULL
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE articles_plain (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry     = new MapperRegistry();
        $this->articleMapper = new ArticleMapper();
        $this->plainMapper   = new ArticleNoSoftDeleteMapper();
        $this->registry->register($this->articleMapper);
        $this->registry->register($this->plainMapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(?QueryFilterRegistry $registry = null): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            connection:     $this->connection,
            entityClass:    Article::class,
            mapper:         $this->articleMapper,
            hydrator:       $this->hydrator,
            filterRegistry: $registry,
        );
    }

    private function makePlainQb(?QueryFilterRegistry $registry = null): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            connection:     $this->connection,
            entityClass:    ArticleNoSoftDelete::class,
            mapper:         $this->plainMapper,
            hydrator:       $this->hydrator,
            filterRegistry: $registry,
        );
    }

    private function seedArticles(): void
    {
        $this->connection->insert('articles', ['title' => 'Live Article',    'deleted_at' => null]);
        $this->connection->insert('articles', ['title' => 'Deleted Article', 'deleted_at' => '2024-01-01 00:00:00']);
        $this->connection->insert('articles', ['title' => 'Also Live',       'deleted_at' => null]);
    }





    public function test_without_filter_returns_all_rows(): void
    {
        $this->seedArticles();



        $result = $this->makeQb()->withTrashed()->get();

        self::assertCount(3, $result);
    }





    public function test_soft_delete_filter_excludes_deleted_rows(): void
    {
        $this->seedArticles();

        $filterRegistry = new QueryFilterRegistry();
        $filterRegistry->add(new SoftDeleteFilter());



        $result = $this->makeQb($filterRegistry)->withTrashed()->get();

        self::assertCount(2, $result);

        foreach ($result as $article) {
            self::assertNull($article->deletedAt);
        }
    }





    public function test_filter_for_specific_entity_does_not_apply_to_others(): void
    {

        $this->connection->insert('articles_plain', ['title' => 'Plain 1']);
        $this->connection->insert('articles_plain', ['title' => 'Plain 2']);

        $filterRegistry = new QueryFilterRegistry();

        $filterRegistry->add(new SoftDeleteFilter(
            entityClasses: [Article::class],
        ));

        $plainResult = $this->makePlainQb($filterRegistry)->get();


        self::assertCount(2, $plainResult);
    }





    public function test_remove_filter_stops_applying_it(): void
    {
        $this->seedArticles();

        $filterRegistry = new QueryFilterRegistry();
        $filter = new SoftDeleteFilter();
        $filterRegistry->add($filter);


        $resultWithFilter = $this->makeQb($filterRegistry)->withTrashed()->get();
        self::assertCount(2, $resultWithFilter);


        $filterRegistry->remove($filter);


        $resultWithoutFilter = $this->makeQb($filterRegistry)->withTrashed()->get();
        self::assertCount(3, $resultWithoutFilter);
    }





    public function test_multiple_filters_both_applied(): void
    {

        $this->connection->insert('articles', ['title' => '',               'deleted_at' => null]);
        $this->connection->insert('articles', ['title' => 'Has Title',      'deleted_at' => null]);
        $this->connection->insert('articles', ['title' => 'Deleted',        'deleted_at' => '2024-01-01 00:00:00']);

        $filterRegistry = new QueryFilterRegistry();
        $filterRegistry->add(new SoftDeleteFilter());
        $filterRegistry->add(new NonEmptyTitleFilter());

        $result = $this->makeQb($filterRegistry)->withTrashed()->get();


        self::assertCount(1, $result);
        self::assertSame('Has Title', $result->first()->title);
    }





    public function test_soft_delete_filter_custom_column(): void
    {

        $this->connection->executeStatement(
            'CREATE TABLE posts_archived (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                title       TEXT    NOT NULL DEFAULT \'\',
                archived_at TEXT    NULL
            )'
        );

        $this->connection->insert('posts_archived', ['title' => 'Active Post',   'archived_at' => null]);
        $this->connection->insert('posts_archived', ['title' => 'Archived Post', 'archived_at' => '2024-06-01']);


        $archivedMapper = new class extends AbstractEntityMapper {
            public function getEntityClass(): string { return \stdClass::class; }
            public function getTableName(): string { return 'posts_archived'; }
            public function getColumns(): array {
                return [
                    new ColumnDefinition('id',          'id',         'integer', primary: true, autoIncrement: true),
                    new ColumnDefinition('title',       'title',      'string',  length: 255),
                    new ColumnDefinition('archived_at', 'archivedAt', 'string',  nullable: true),
                ];
            }
        };

        $filterRegistry = new QueryFilterRegistry();
        $filterRegistry->add(new SoftDeleteFilter(column: 'archived_at'));

        $qb = new EntityQueryBuilder(
            connection:     $this->connection,
            entityClass:    \stdClass::class,
            mapper:         $archivedMapper,
            filterRegistry: $filterRegistry,
        );

        $rows = $qb->fetchRaw();

        self::assertCount(1, $rows);
        self::assertSame('Active Post', $rows[0]['title']);
    }





    public function test_filter_supports_returns_false_for_non_matching_class(): void
    {
        $filter = new SoftDeleteFilter(entityClasses: [Article::class]);

        self::assertTrue($filter->supports(Article::class));
        self::assertFalse($filter->supports(ArticleNoSoftDelete::class));
        self::assertFalse($filter->supports(\stdClass::class));
    }





    public function test_has_filters_returns_correct_boolean(): void
    {
        $registry = new QueryFilterRegistry();

        self::assertFalse($registry->hasFilters(Article::class));

        $filter = new SoftDeleteFilter(entityClasses: [Article::class]);
        $registry->add($filter);

        self::assertTrue($registry->hasFilters(Article::class));
        self::assertFalse($registry->hasFilters(ArticleNoSoftDelete::class));

        $registry->remove($filter);

        self::assertFalse($registry->hasFilters(Article::class));
    }
}
