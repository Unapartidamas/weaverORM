<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\DBAL\Platform\PostgresPlatform;
use Weaver\ORM\Query\EntityQueryBuilder;

class FtsDoc
{
    public ?int $id    = null;
    public string $title = '';
    public string $body  = '';
}

class FtsDocMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return FtsDoc::class;
    }

    public function getTableName(): string
    {
        return 'fts_docs';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
            new ColumnDefinition('body',  'body',  'string',  length: 2000),
        ];
    }
}

final class FullTextSearchTest extends TestCase
{
    private Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private FtsDocMapper $mapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE fts_docs (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\',
                body  TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->mapper   = new FtsDocMapper();
        $this->registry->register($this->mapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            FtsDoc::class,
            $this->mapper,
            $this->hydrator,
        );
    }

    private function seed(): void
    {
        $rows = [
            ['Hello World introduction', 'This article is about hello.'],
            ['PHP Tutorial',             'World class PHP tips and tricks.'],
            ['Database Design',          'Relational schema patterns explained.'],
            ['Hello Again',              'Another hello world example body.'],
        ];

        foreach ($rows as [$title, $body]) {
            $this->connection->insert('fts_docs', ['title' => $title, 'body' => $body]);
        }
    }





    public function test_where_full_text_single_column_matches_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereFullText('title', 'hello')->get();


        self::assertCount(2, $result);
        foreach ($result as $doc) {
            self::assertStringContainsStringIgnoringCase('hello', $doc->title);
        }
    }





    public function test_where_full_text_multiple_columns_searches_both(): void
    {
        $this->seed();



        $result = $this->makeQb()->whereFullText(['title', 'body'], 'hello')->get();

        self::assertGreaterThanOrEqual(2, count($result));

        $found = false;
        foreach ($result as $doc) {
            if (
                stripos($doc->title, 'hello') !== false
                || stripos($doc->body, 'hello') !== false
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected at least one doc containing "hello" in title or body.');
    }





    public function test_where_full_text_multi_word_matches_either_word(): void
    {
        $this->seed();


        $result = $this->makeQb()->whereFullText('title', 'hello world')->get();


        self::assertGreaterThanOrEqual(2, count($result));

        foreach ($result as $doc) {
            $hasHello = stripos($doc->title, 'hello') !== false;
            $hasWorld = stripos($doc->title, 'world') !== false;
            self::assertTrue(
                $hasHello || $hasWorld,
                "Doc title '{$doc->title}' should contain 'hello' or 'world'."
            );
        }
    }





    public function test_where_full_text_no_match_returns_empty(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereFullText('title', 'xyznonexistent')->get();

        self::assertCount(0, $result);
    }





    public function test_where_full_text_boolean_returns_matching_rows(): void
    {
        $this->seed();

        $result = $this->makeQb()->whereFullTextBoolean('title', 'hello')->get();

        self::assertGreaterThanOrEqual(1, count($result));
        foreach ($result as $doc) {
            self::assertStringContainsStringIgnoringCase('hello', $doc->title);
        }
    }





    public function test_postgres_syntax_used_on_postgresql_platform(): void
    {

        $pgConnection = $this->createMock(Connection::class);
        $pgPlatform   = new PostgresPlatform();

        $pgConnection->method('getDatabasePlatform')->willReturn($pgPlatform);



        $innerQb = $this->connection->createQueryBuilder();
        $pgConnection->method('createQueryBuilder')->willReturn($innerQb);

        $qb = new EntityQueryBuilder(
            $pgConnection,
            FtsDoc::class,
            $this->mapper,
            null,
        );

        $sql = $qb->whereFullText('title', 'hello')->toSQL();

        self::assertStringContainsString('to_tsvector', $sql, 'PostgreSQL SQL should use to_tsvector().');
        self::assertStringContainsString('plainto_tsquery', $sql, 'PostgreSQL SQL should use plainto_tsquery().');
        self::assertStringNotContainsString('LIKE', $sql, 'PostgreSQL SQL should NOT use LIKE fallback.');
    }
}
