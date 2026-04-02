<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Article;

final class AttributeMapperFactoryTest extends TestCase
{
    private AttributeMapperFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMapperFactory();
    }

    public function test_builds_mapper_with_correct_table_name(): void
    {
        $mapper = $this->factory->build(Article::class);

        self::assertSame('articles', $mapper->getTableName());
        self::assertSame(Article::class, $mapper->getEntityClass());
    }

    public function test_builds_mapper_with_id_column(): void
    {
        $mapper  = $this->factory->build(Article::class);
        $idCol   = $mapper->getColumn('id');

        self::assertNotNull($idCol);
        self::assertTrue($idCol->isPrimary());
        self::assertTrue($idCol->isAutoIncrement());
        self::assertSame('integer', $idCol->getType());
        self::assertSame('id', $idCol->getColumn());
    }

    public function test_builds_mapper_with_string_column(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $titleCol = $mapper->getColumn('title');

        self::assertNotNull($titleCol);
        self::assertSame('string', $titleCol->getType());
        self::assertFalse($titleCol->isNullable());
    }

    public function test_column_name_defaults_to_snake_case_of_property(): void
    {



        $mapper   = $this->factory->build(Article::class);
        $titleCol = $mapper->getColumn('title');

        self::assertNotNull($titleCol);
        self::assertSame('title', $titleCol->getColumn());
    }

    public function test_nullable_column(): void
    {
        $mapper  = $this->factory->build(Article::class);
        $bodyCol = $mapper->getColumn('body');

        self::assertNotNull($bodyCol);
        self::assertTrue($bodyCol->isNullable());
        self::assertSame('text', $bodyCol->getType());
    }

    public function test_timestamps_adds_created_at_and_updated_at(): void
    {
        $mapper    = $this->factory->build(Article::class);
        $createdAt = $mapper->getColumn('createdAt');
        $updatedAt = $mapper->getColumn('updatedAt');

        self::assertNotNull($createdAt);
        self::assertSame('created_at', $createdAt->getColumn());
        self::assertSame('datetime_immutable', $createdAt->getType());
        self::assertTrue($createdAt->isNullable());

        self::assertNotNull($updatedAt);
        self::assertSame('updated_at', $updatedAt->getColumn());
        self::assertSame('datetime_immutable', $updatedAt->getType());
        self::assertTrue($updatedAt->isNullable());
    }

    public function test_builds_belongs_to_relation(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $relation = $mapper->getRelation('author');

        self::assertNotNull($relation);
        self::assertSame(RelationType::BelongsTo, $relation->getType());
        self::assertSame('user_id', $relation->getForeignKey());
    }

    public function test_builds_has_many_relation(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $relation = $mapper->getRelation('comments');

        self::assertNotNull($relation);
        self::assertSame(RelationType::HasMany, $relation->getType());
        self::assertSame('article_id', $relation->getForeignKey());
    }

    public function test_throws_when_entity_attribute_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no #\[Entity\] attribute/');


        $this->factory->build(\Weaver\ORM\Tests\Fixture\Entity\User::class);
    }
}
