<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\MongoDB;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;
use Weaver\ORM\MongoDB\Mapping\FieldDefinition;
use Weaver\ORM\MongoDB\Mapping\FieldType;
use Weaver\ORM\Tests\Fixture\MongoDB\Article;
use Weaver\ORM\Tests\Fixture\MongoDB\ArticleMapper;

enum StatusEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

final class DocumentMapperTest extends TestCase
{
    private ArticleMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ArticleMapper();
    }

    public function test_hydrate_sets_string_properties(): void
    {
        $article = $this->mapper->hydrate([
            '_id'   => 'abc123',
            'title' => 'Hello World',
            'status' => 'draft',
        ]);

        $this->assertInstanceOf(Article::class, $article);
        $this->assertSame('abc123', $article->id);
        $this->assertSame('Hello World', $article->title);
        $this->assertSame('draft', $article->status);
    }

    public function test_hydrate_sets_int_property(): void
    {
        $article = $this->mapper->hydrate(['views' => 42]);

        $this->assertSame(42, $article->views);
    }

    public function test_hydrate_sets_float_property(): void
    {
        $article = $this->mapper->hydrate(['score' => 9.5]);

        $this->assertSame(9.5, $article->score);
    }

    public function test_hydrate_sets_bool_property(): void
    {
        $article = $this->mapper->hydrate(['published' => true]);

        $this->assertTrue($article->published);
    }

    public function test_hydrate_sets_array_property(): void
    {
        $article = $this->mapper->hydrate(['tags' => ['php', 'mongodb']]);

        $this->assertSame(['php', 'mongodb'], $article->tags);
    }

    public function test_hydrate_skips_missing_fields(): void
    {
        $article = $this->mapper->hydrate(['title' => 'Only Title']);

        $this->assertSame('Only Title', $article->title);
        $this->assertSame('', $article->status);
        $this->assertSame(0, $article->views);
    }

    public function test_hydrate_maps_null_values(): void
    {
        $article = $this->mapper->hydrate(['author_id' => null]);

        $this->assertNull($article->authorId);
    }

    public function test_hydrate_maps__id_to_id_when_id_not_in_document(): void
    {
        $article = $this->mapper->hydrate(['_id' => 'abc123', 'title' => 'Test']);

        $this->assertSame('abc123', $article->id);
        $this->assertSame('Test', $article->title);
    }

    public function test_extract_returns_array_keyed_by_field_name(): void
    {
        $article            = new Article();
        $article->id        = 'abc123';
        $article->title     = 'Hello';
        $article->status    = 'draft';
        $article->views     = 5;
        $article->score     = 1.5;
        $article->published = false;
        $article->authorId  = null;
        $article->tags      = ['a', 'b'];

        $doc = $this->mapper->extract($article);

        $this->assertArrayHasKey('_id', $doc);
        $this->assertArrayHasKey('title', $doc);
        $this->assertArrayHasKey('status', $doc);
        $this->assertArrayHasKey('views', $doc);
        $this->assertArrayHasKey('score', $doc);
        $this->assertArrayHasKey('published', $doc);
        $this->assertArrayHasKey('author_id', $doc);
        $this->assertArrayHasKey('tags', $doc);

        $this->assertSame('abc123', $doc['_id']);
        $this->assertSame('Hello', $doc['title']);
        $this->assertSame(5, $doc['views']);
        $this->assertSame(['a', 'b'], $doc['tags']);
    }

    public function test_extract_unwraps_backed_enum(): void
    {
        $entity = new class {
            public ?StatusEnum $status = null;
        };

        $entityClass = $entity::class;

        $enumMapper = new class ($entityClass) extends AbstractDocumentMapper {
            public function __construct(private string $entityClass) {}

            public function getDocumentClass(): string
            {
                return $this->entityClass;
            }

            public function getCollectionName(): string
            {
                return 'articles';
            }

            public function getFields(): array
            {
                return [
                    new FieldDefinition('status', 'status', FieldType::String, enumClass: StatusEnum::class),
                ];
            }
        };

        $hydratedEntity = $enumMapper->hydrate(['status' => 'active']);

        $this->assertSame(StatusEnum::Active, $hydratedEntity->status);

        $entity2         = new ($entityClass)();
        $entity2->status = StatusEnum::Active;

        $doc = $enumMapper->extract($entity2);

        $this->assertSame('active', $doc['status']);
    }

    public function test_get_field_returns_definition_by_property(): void
    {
        $def = $this->mapper->getField('title');

        $this->assertNotNull($def);
        $this->assertSame('title', $def->getProperty());
        $this->assertSame('title', $def->getField());
    }

    public function test_get_field_returns_null_for_unknown(): void
    {
        $this->assertNull($this->mapper->getField('nonexistent'));
    }

    public function test_get_field_by_name_returns_definition_by_field_name(): void
    {
        $def = $this->mapper->getFieldByName('author_id');

        $this->assertNotNull($def);
        $this->assertSame('author_id', $def->getField());
        $this->assertSame('authorId', $def->getProperty());
    }

    public function test_new_instance_returns_article(): void
    {
        $instance = $this->mapper->newInstance();

        $this->assertInstanceOf(Article::class, $instance);
    }

    public function test_set_property_assigns_value(): void
    {
        $article = new Article();

        $this->mapper->setProperty($article, 'title', 'Assigned');

        $this->assertSame('Assigned', $article->title);
    }

    public function test_get_property_reads_value(): void
    {
        $article        = new Article();
        $article->views = 99;

        $this->assertSame(99, $this->mapper->getProperty($article, 'views'));
    }
}
