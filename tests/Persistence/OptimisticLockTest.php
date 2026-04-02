<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Persistence;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Exception\OptimisticLockException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\Version;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;

class OLockDocument
{
    public ?int $id      = null;
    public string $title = '';
    public int $version  = 1;
}

class OLockDocumentMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return OLockDocument::class; }
    public function getTableName(): string   { return 'ol_documents'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',      'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('title',   'title',   'string',  length: 255),
            new ColumnDefinition('version', 'version', 'integer', version: true),
        ];
    }
}

class OLockNote
{
    public ?int $id      = null;
    public string $body  = '';
}

class OLockNoteMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string { return OLockNote::class; }
    public function getTableName(): string   { return 'ol_notes'; }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('body', 'body', 'string',  length: 255),
        ];
    }
}

#[Entity(table: 'ol_attr_items')]
class OLockAttrItem
{
    #[Id(type: 'integer', autoIncrement: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    public string $label = '';

    #[Version]
    #[Column(type: 'integer')]
    public int $version = 1;
}

#[Entity(table: 'ol_attr_standalone')]
class OLockAttrStandalone
{
    #[Id(type: 'integer', autoIncrement: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    public string $name = '';

    #[Version]
    public int $version = 1;
}

final class OptimisticLockTest extends TestCase
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
            'CREATE TABLE ol_documents (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                title   TEXT    NOT NULL DEFAULT \'\',
                version INTEGER NOT NULL DEFAULT 1
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE ol_notes (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $this->registry = new MapperRegistry();
        $this->registry->register(new OLockDocumentMapper());
        $this->registry->register(new OLockNoteMapper());

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





    public function test_version_is_incremented_on_update(): void
    {
        $doc        = new OLockDocument();
        $doc->title = 'Original';
        $doc->version = 1;

        $this->uow->add($doc);
        $this->uow->push();

        self::assertNotNull($doc->id);


        $doc->title = 'Updated';
        $this->uow->push();


        self::assertSame(2, $doc->version);


        $row = $this->connection->fetchAssociative(
            'SELECT version FROM ol_documents WHERE id = ?',
            [$doc->id],
        );
        self::assertSame(2, (int) $row['version']);
    }





    public function test_two_sequential_updates_increment_version_twice(): void
    {
        $doc        = new OLockDocument();
        $doc->title = 'First';
        $doc->version = 1;

        $this->uow->add($doc);
        $this->uow->push();

        $doc->title = 'Second';
        $this->uow->push();
        self::assertSame(2, $doc->version);

        $doc->title = 'Third';
        $this->uow->push();
        self::assertSame(3, $doc->version);

        $row = $this->connection->fetchAssociative(
            'SELECT version FROM ol_documents WHERE id = ?',
            [$doc->id],
        );
        self::assertSame(3, (int) $row['version']);
    }





    public function test_stale_version_throws_optimistic_lock_exception(): void
    {
        $doc        = new OLockDocument();
        $doc->title = 'Concurrent';
        $doc->version = 1;

        $this->uow->add($doc);
        $this->uow->push();


        $this->connection->executeStatement(
            'UPDATE ol_documents SET version = 2, title = \'Modified by other\' WHERE id = ?',
            [$doc->id],
        );


        $doc->title = 'My Change';

        $this->expectException(OptimisticLockException::class);
        $this->uow->push();
    }





    public function test_entities_without_version_update_normally(): void
    {
        $note      = new OLockNote();
        $note->body = 'Hello';

        $this->uow->add($note);
        $this->uow->push();

        self::assertNotNull($note->id);

        $note->body = 'World';
        $this->uow->push();

        $row = $this->connection->fetchAssociative(
            'SELECT body FROM ol_notes WHERE id = ?',
            [$note->id],
        );
        self::assertSame('World', $row['body']);
    }





    public function test_version_column_is_detectable_via_mapper(): void
    {
        $mapper = $this->registry->get(OLockDocument::class);

        $versionCol = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->isVersion()) {
                $versionCol = $col;
                break;
            }
        }

        self::assertNotNull($versionCol, 'Expected to find a version column in OLockDocumentMapper.');
        self::assertSame('version', $versionCol->getColumn());
        self::assertSame('version', $versionCol->getProperty());
    }





    public function test_attribute_mapper_factory_marks_version_column(): void
    {
        $factory = new AttributeMapperFactory();
        $mapper  = $factory->build(OLockAttrItem::class);

        $versionCol = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->isVersion()) {
                $versionCol = $col;
                break;
            }
        }

        self::assertNotNull($versionCol, 'Expected AttributeMapperFactory to mark the version column.');
        self::assertSame('version', $versionCol->getColumn());
        self::assertTrue($versionCol->isVersion());
    }





    public function test_standalone_version_attribute_detected_by_factory(): void
    {
        $factory = new AttributeMapperFactory();
        $mapper  = $factory->build(OLockAttrStandalone::class);

        $versionCol = null;
        foreach ($mapper->getColumns() as $col) {
            if ($col->isVersion()) {
                $versionCol = $col;
                break;
            }
        }

        self::assertNotNull($versionCol, 'Expected standalone #[Version] to be detected by AttributeMapperFactory.');
        self::assertSame('version', $versionCol->getColumn());
        self::assertSame('integer', $versionCol->getType());
    }
}
