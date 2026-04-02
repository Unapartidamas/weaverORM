<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Relation;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Relation\RelationLoader;

class ELAuthor
{
    public ?int $id    = null;
    public string $name = '';

    public array $books  = [];
}

class ELBook
{
    public ?int $id          = null;
    public string $title      = '';
    public ?int $authorId     = null;
    public ?ELAuthor $author  = null;
}

class ELUser
{
    public ?int $id   = null;
    public string $name = '';

    public array $posts = [];
}

class ELPost
{
    public ?int $id       = null;
    public string $body    = '';
    public ?int $userId   = null;

    public array $comments = [];
}

class ELComment
{
    public ?int $id      = null;
    public string $text   = '';
    public ?int $postId  = null;
}

class ELAuthorMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ELAuthor::class; }
    public function getTableName(): string   { return 'el_authors'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                'books',
                RelationType::HasMany,
                ELBook::class,
                ELBookMapper::class,
                foreignKey: 'author_id',
            ),
        ];
    }
}

class ELBookMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ELBook::class; }
    public function getTableName(): string   { return 'el_books'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',        'id',       'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title',     'title',    'string',  length: 255),
            new ColumnDefinition('author_id', 'authorId', 'integer', nullable: true),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                'author',
                RelationType::BelongsTo,
                ELAuthor::class,
                ELAuthorMapper::class,
                foreignKey: 'author_id',
            ),
        ];
    }
}

class ELUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ELUser::class; }
    public function getTableName(): string   { return 'el_users'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string',  length: 100),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                'posts',
                RelationType::HasMany,
                ELPost::class,
                ELPostMapper::class,
                foreignKey: 'user_id',
            ),
        ];
    }
}

class ELPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ELPost::class; }
    public function getTableName(): string   { return 'el_posts'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('body',    'body',   'string',  length: 255),
            new ColumnDefinition('user_id', 'userId', 'integer', nullable: true),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                'comments',
                RelationType::HasMany,
                ELComment::class,
                ELCommentMapper::class,
                foreignKey: 'post_id',
            ),
        ];
    }
}

class ELCommentMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return ELComment::class; }
    public function getTableName(): string   { return 'el_comments'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('text',    'text',   'string',  length: 255),
            new ColumnDefinition('post_id', 'postId', 'integer', nullable: true),
        ];
    }
}

final class EagerLoadingTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private RelationLoader $relationLoader;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE el_authors (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $this->connection->executeStatement(
            'CREATE TABLE el_books (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                title     TEXT    NOT NULL DEFAULT \'\',
                author_id INTEGER NULL
            )'
        );
        $this->connection->executeStatement(
            'CREATE TABLE el_users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $this->connection->executeStatement(
            'CREATE TABLE el_posts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                body    TEXT    NOT NULL DEFAULT \'\',
                user_id INTEGER NULL
            )'
        );
        $this->connection->executeStatement(
            'CREATE TABLE el_comments (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                text    TEXT    NOT NULL DEFAULT \'\',
                post_id INTEGER NULL
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new ELAuthorMapper());
        $this->registry->register(new ELBookMapper());
        $this->registry->register(new ELUserMapper());
        $this->registry->register(new ELPostMapper());
        $this->registry->register(new ELCommentMapper());

        $this->hydrator       = new EntityHydrator($this->registry, $this->connection);
        $this->relationLoader = new RelationLoader($this->connection, $this->registry, $this->hydrator);
    }





    private function authorQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            ELAuthor::class,
            $this->registry->get(ELAuthor::class),
            $this->hydrator,
            $this->relationLoader,
        );
    }

    private function bookQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            ELBook::class,
            $this->registry->get(ELBook::class),
            $this->hydrator,
            $this->relationLoader,
        );
    }

    private function userQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            ELUser::class,
            $this->registry->get(ELUser::class),
            $this->hydrator,
            $this->relationLoader,
        );
    }



    public function test_has_many_eager_loads_related_collection(): void
    {
        $this->connection->insert('el_authors', ['name' => 'J.K. Rowling']);
        $authorId = (int) $this->connection->lastInsertId();

        $this->connection->insert('el_books', ['title' => 'Book One', 'author_id' => $authorId]);
        $this->connection->insert('el_books', ['title' => 'Book Two', 'author_id' => $authorId]);


        $authors = $this->authorQb()->get(['books']);

        self::assertCount(1, $authors);

        $author = $authors->first();
        self::assertInstanceOf(ELAuthor::class, $author);

        $books = is_array($author->books) ? $author->books : $author->books->toArray();
        self::assertCount(2, $books);
    }

    public function test_belongs_to_eager_loads_parent(): void
    {
        $this->connection->insert('el_authors', ['name' => 'Author A']);
        $authorId = (int) $this->connection->lastInsertId();

        $this->connection->insert('el_books', ['title' => 'My Book', 'author_id' => $authorId]);


        $books = $this->bookQb()->get(['author']);

        self::assertCount(1, $books);

        $book = $books->first();
        self::assertInstanceOf(ELBook::class, $book);
        self::assertNotNull($book->author);
        self::assertInstanceOf(ELAuthor::class, $book->author);
        self::assertSame('Author A', $book->author->name);
    }

    public function test_eager_load_empty_relation_sets_empty_collection(): void
    {
        $this->connection->insert('el_authors', ['name' => 'Lonely Author']);


        $authors = $this->authorQb()->get(['books']);

        self::assertCount(1, $authors);

        $author = $authors->first();
        $books  = is_array($author->books) ? $author->books : $author->books->toArray();
        self::assertCount(0, $books);
    }

    public function test_nested_eager_load(): void
    {
        $this->connection->insert('el_users', ['name' => 'Alice']);
        $userId = (int) $this->connection->lastInsertId();

        $this->connection->insert('el_posts', ['body' => 'Post A', 'user_id' => $userId]);
        $postId = (int) $this->connection->lastInsertId();

        $this->connection->insert('el_comments', ['text' => 'Comment 1', 'post_id' => $postId]);
        $this->connection->insert('el_comments', ['text' => 'Comment 2', 'post_id' => $postId]);


        $users = $this->userQb()->get(['posts.comments']);

        self::assertCount(1, $users);

        $user  = $users->first();
        $posts = is_array($user->posts) ? $user->posts : $user->posts->toArray();
        self::assertCount(1, $posts);

        $post     = $posts[0];
        $comments = is_array($post->comments) ? $post->comments : $post->comments->toArray();
        self::assertCount(2, $comments);
    }

    public function test_eager_loading_uses_batch_in_query(): void
    {

        for ($i = 1; $i <= 3; $i++) {
            $this->connection->insert('el_authors', ['name' => "Author {$i}"]);
            $authorId = (int) $this->connection->lastInsertId();

            for ($j = 1; $j <= 2; $j++) {
                $this->connection->insert('el_books', ['title' => "Book {$j} of Author {$i}", 'author_id' => $authorId]);
            }
        }



        $authors = $this->authorQb()->get(['books']);

        self::assertCount(3, $authors);

        foreach ($authors as $author) {
            $books = is_array($author->books) ? $author->books : $author->books->toArray();
            self::assertCount(2, $books, "Each author must have exactly 2 books");
        }
    }
}
