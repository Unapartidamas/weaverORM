<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\Attribute\BelongsToMany;
use Weaver\ORM\Mapping\Attribute\HasMany;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;

final class OrderByAttributeTest extends TestCase
{




    public function test_has_many_defaults_to_empty_order_by(): void
    {
        $attr = new HasMany('App\Post', 'user_id');

        $this->assertSame([], $attr->orderBy);
    }

    public function test_has_many_stores_single_order_by_asc(): void
    {
        $attr = new HasMany('App\Post', 'user_id', orderBy: ['created_at' => 'ASC']);

        $this->assertSame(['created_at' => 'ASC'], $attr->orderBy);
    }

    public function test_has_many_stores_single_order_by_desc(): void
    {
        $attr = new HasMany('App\Post', 'user_id', orderBy: ['published_at' => 'DESC']);

        $this->assertSame(['published_at' => 'DESC'], $attr->orderBy);
    }

    public function test_has_many_stores_multiple_order_by_columns(): void
    {
        $attr = new HasMany('App\Post', 'user_id', orderBy: ['title' => 'ASC', 'created_at' => 'DESC']);

        $this->assertSame(['title' => 'ASC', 'created_at' => 'DESC'], $attr->orderBy);
    }





    public function test_belongs_to_many_defaults_to_empty_order_by(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id');

        $this->assertSame([], $attr->orderBy);
    }

    public function test_belongs_to_many_stores_single_order_by_asc(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id', orderBy: ['name' => 'ASC']);

        $this->assertSame(['name' => 'ASC'], $attr->orderBy);
    }

    public function test_belongs_to_many_stores_single_order_by_desc(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id', orderBy: ['created_at' => 'DESC']);

        $this->assertSame(['created_at' => 'DESC'], $attr->orderBy);
    }

    public function test_belongs_to_many_stores_multiple_order_by_columns(): void
    {
        $attr = new BelongsToMany(
            'App\Tag',
            'post_tag',
            'post_id',
            'tag_id',
            orderBy: ['priority' => 'DESC', 'name' => 'ASC'],
        );

        $this->assertSame(['priority' => 'DESC', 'name' => 'ASC'], $attr->orderBy);
    }





    public function test_relation_definition_defaults_to_empty_order_by(): void
    {
        $rel = new RelationDefinition(
            property: 'posts',
            type: RelationType::HasMany,
            relatedEntity: 'App\Post',
            relatedMapper: 'App\PostMapper',
        );

        $this->assertSame([], $rel->getOrderBy());
    }

    public function test_relation_definition_stores_order_by(): void
    {
        $rel = new RelationDefinition(
            property: 'posts',
            type: RelationType::HasMany,
            relatedEntity: 'App\Post',
            relatedMapper: 'App\PostMapper',
            orderBy: ['created_at' => 'DESC'],
        );

        $this->assertSame(['created_at' => 'DESC'], $rel->getOrderBy());
    }

    public function test_relation_definition_stores_multiple_order_by_columns(): void
    {
        $rel = new RelationDefinition(
            property: 'tags',
            type: RelationType::BelongsToMany,
            relatedEntity: 'App\Tag',
            relatedMapper: 'App\TagMapper',
            orderBy: ['name' => 'ASC', 'id' => 'DESC'],
        );

        $this->assertSame(['name' => 'ASC', 'id' => 'DESC'], $rel->getOrderBy());
    }
}
