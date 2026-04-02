<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\CascadeType;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class CascadePost
{
    public int $id      = 0;
    public string $title = '';
    public ?CascadeComment $comment = null;
}

class CascadeComment
{
    public int $id     = 0;
    public string $body = '';
}

final class CascadePostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return CascadePost::class;
    }

    public function getTableName(): string
    {
        return 'cascade_posts';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
        ];
    }


    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                property:      'comment',
                type:          RelationType::HasOne,
                relatedEntity: CascadeComment::class,
                relatedMapper: CascadeCommentMapper::class,
                foreignKey:    'post_id',
                cascade:       [CascadeType::Persist, CascadeType::Remove],
            ),
        ];
    }
}

final class CascadePostNoCascadeMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {

        return CascadePost::class;
    }

    public function getTableName(): string
    {
        return 'cascade_posts_no_cascade';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title', 'title', 'string',  length: 255),
        ];
    }


    public function getRelations(): array
    {
        return [
            new RelationDefinition(
                property:      'comment',
                type:          RelationType::HasOne,
                relatedEntity: CascadeComment::class,
                relatedMapper: CascadeCommentMapper::class,
                foreignKey:    'post_id',
                cascade:       [],
            ),
        ];
    }
}

final class CascadeCommentMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return CascadeComment::class;
    }

    public function getTableName(): string
    {
        return 'cascade_comments';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('body', 'body', 'string',  length: 255),
        ];
    }
}

final class CascadeFlushTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE cascade_posts (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE cascade_posts_no_cascade (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE cascade_comments (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                body    TEXT    NOT NULL DEFAULT \'\',
                post_id INTEGER NOT NULL DEFAULT 0
            )'
        );
    }





    private function makeUow(AbstractEntityMapper ...$mappers): UnitOfWork
    {
        $registry = new MapperRegistry();
        foreach ($mappers as $mapper) {
            $registry->register($mapper);
        }

        $hydrator   = new EntityHydrator($registry, $this->connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);

        return new UnitOfWork($this->connection, $registry, $hydrator, $dispatcher, $resolver);
    }

    private function countRows(string $table): int
    {
        $result = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        return (int) $result;
    }





    public function test_cascade_persist_auto_inserts_related_entity_on_full_flush(): void
    {
        $uow = $this->makeUow(new CascadePostMapper(), new CascadeCommentMapper());

        $comment       = new CascadeComment();
        $comment->body = 'Hello cascade';

        $post          = new CascadePost();
        $post->title   = 'First Post';
        $post->comment = $comment;

        $uow->add($post);
        $uow->push();

        self::assertSame(1, $this->countRows('cascade_posts'),    'Post must be inserted');
        self::assertSame(1, $this->countRows('cascade_comments'), 'Comment must be cascade-inserted');
        self::assertGreaterThan(0, $comment->id, 'Comment auto-increment ID must be set after flush');
    }





    public function test_cascade_persist_does_not_double_insert_already_tracked_comment(): void
    {
        $uow = $this->makeUow(new CascadePostMapper(), new CascadeCommentMapper());

        $comment       = new CascadeComment();
        $comment->body = 'Already persisted';


        $uow->add($comment);
        $uow->push();

        self::assertSame(1, $this->countRows('cascade_comments'), 'Comment must be in DB after first flush');

        $post          = new CascadePost();
        $post->title   = 'Second Post';
        $post->comment = $comment;

        $uow->add($post);
        $uow->push();

        self::assertSame(1, $this->countRows('cascade_posts'),    'Post must be inserted');
        self::assertSame(1, $this->countRows('cascade_comments'), 'Comment must NOT be double-inserted');
    }





    public function test_no_cascade_relation_does_not_auto_insert_related_entity(): void
    {


        $uow = $this->makeUow(new CascadePostNoCascadeMapper(), new CascadeCommentMapper());

        $comment       = new CascadeComment();
        $comment->body = 'Should NOT be auto-inserted';

        $post          = new CascadePost();
        $post->title   = 'Post Without Cascade';
        $post->comment = $comment;

        $uow->add($post);
        $uow->push();

        self::assertSame(1, $this->countRows('cascade_posts_no_cascade'), 'Post must be inserted');
        self::assertSame(0, $this->countRows('cascade_comments'),          'Comment must NOT be auto-inserted without cascade');
    }





    public function test_selective_flush_also_cascades_comment_insert(): void
    {
        $uow = $this->makeUow(new CascadePostMapper(), new CascadeCommentMapper());

        $comment       = new CascadeComment();
        $comment->body = 'Selective cascade comment';

        $post          = new CascadePost();
        $post->title   = 'Selective Post';
        $post->comment = $comment;

        $uow->add($post);
        $uow->push($post);

        self::assertSame(1, $this->countRows('cascade_posts'),    'Post must be inserted via selective flush');
        self::assertSame(1, $this->countRows('cascade_comments'), 'Comment must be cascade-inserted via selective flush');
        self::assertGreaterThan(0, $comment->id, 'Comment auto-increment ID must be set after selective flush');
    }





    public function test_cascade_remove_deletes_related_comment_when_post_is_deleted(): void
    {
        $uow = $this->makeUow(new CascadePostMapper(), new CascadeCommentMapper());

        $comment       = new CascadeComment();
        $comment->body = 'Will be removed';

        $post          = new CascadePost();
        $post->title   = 'Post to Delete';
        $post->comment = $comment;


        $uow->add($post);
        $uow->push();

        self::assertSame(1, $this->countRows('cascade_posts'),    'Post must exist before delete');
        self::assertSame(1, $this->countRows('cascade_comments'), 'Comment must exist before delete');


        $uow->delete($post);
        $uow->push();

        self::assertSame(0, $this->countRows('cascade_posts'),    'Post must be deleted');
        self::assertSame(0, $this->countRows('cascade_comments'), 'Comment must be cascade-deleted');
    }
}
