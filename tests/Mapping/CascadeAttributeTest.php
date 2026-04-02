<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\Attribute\BelongsTo;
use Weaver\ORM\Mapping\Attribute\BelongsToMany;
use Weaver\ORM\Mapping\Attribute\HasMany;
use Weaver\ORM\Mapping\Attribute\HasOne;
use Weaver\ORM\Mapping\CascadeType;

final class CascadeAttributeTest extends TestCase
{




    public function test_has_many_defaults_to_empty_cascade(): void
    {
        $attr = new HasMany('App\Post', 'user_id');

        $this->assertSame([], $attr->cascade);
    }

    public function test_has_many_stores_single_cascade_type(): void
    {
        $attr = new HasMany('App\Post', 'user_id', cascade: [CascadeType::Persist]);

        $this->assertSame([CascadeType::Persist], $attr->cascade);
    }

    public function test_has_many_stores_multiple_cascade_types(): void
    {
        $attr = new HasMany('App\Post', 'user_id', cascade: [CascadeType::Persist, CascadeType::Remove]);

        $this->assertContains(CascadeType::Persist, $attr->cascade);
        $this->assertContains(CascadeType::Remove, $attr->cascade);
        $this->assertCount(2, $attr->cascade);
    }

    public function test_has_many_stores_all_cascade_type(): void
    {
        $attr = new HasMany('App\Post', 'user_id', cascade: [CascadeType::All]);

        $this->assertSame([CascadeType::All], $attr->cascade);
    }





    public function test_has_one_defaults_to_empty_cascade(): void
    {
        $attr = new HasOne('App\Profile', 'user_id');

        $this->assertSame([], $attr->cascade);
    }

    public function test_has_one_stores_persist_cascade(): void
    {
        $attr = new HasOne('App\Profile', 'user_id', cascade: [CascadeType::Persist]);

        $this->assertSame([CascadeType::Persist], $attr->cascade);
    }

    public function test_has_one_stores_remove_cascade(): void
    {
        $attr = new HasOne('App\Profile', 'user_id', cascade: [CascadeType::Remove]);

        $this->assertSame([CascadeType::Remove], $attr->cascade);
    }

    public function test_has_one_stores_all_cascade_type(): void
    {
        $attr = new HasOne('App\Profile', 'user_id', cascade: [CascadeType::All]);

        $this->assertSame([CascadeType::All], $attr->cascade);
    }





    public function test_belongs_to_defaults_to_empty_cascade(): void
    {
        $attr = new BelongsTo('App\User', 'user_id');

        $this->assertSame([], $attr->cascade);
    }

    public function test_belongs_to_stores_persist_cascade(): void
    {
        $attr = new BelongsTo('App\User', 'user_id', cascade: [CascadeType::Persist]);

        $this->assertSame([CascadeType::Persist], $attr->cascade);
    }

    public function test_belongs_to_stores_multiple_cascade_types(): void
    {
        $attr = new BelongsTo('App\User', 'user_id', cascade: [CascadeType::Persist, CascadeType::Detach]);

        $this->assertContains(CascadeType::Persist, $attr->cascade);
        $this->assertContains(CascadeType::Detach, $attr->cascade);
        $this->assertCount(2, $attr->cascade);
    }





    public function test_belongs_to_many_defaults_to_empty_cascade(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id');

        $this->assertSame([], $attr->cascade);
    }

    public function test_belongs_to_many_stores_persist_cascade(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id', cascade: [CascadeType::Persist]);

        $this->assertSame([CascadeType::Persist], $attr->cascade);
    }

    public function test_belongs_to_many_stores_all_cascade_type(): void
    {
        $attr = new BelongsToMany('App\Tag', 'post_tag', 'post_id', 'tag_id', cascade: [CascadeType::All]);

        $this->assertSame([CascadeType::All], $attr->cascade);
    }





    public function test_cascade_type_has_persist_case(): void
    {
        $this->assertSame(CascadeType::Persist, CascadeType::Persist);
    }

    public function test_cascade_type_has_remove_case(): void
    {
        $this->assertSame(CascadeType::Remove, CascadeType::Remove);
    }

    public function test_cascade_type_has_all_case(): void
    {
        $this->assertSame(CascadeType::All, CascadeType::All);
    }

    public function test_cascade_type_has_detach_case(): void
    {
        $this->assertSame(CascadeType::Detach, CascadeType::Detach);
    }





    public function test_relation_definition_has_cascade_returns_true_for_explicit_type(): void
    {
        $rel = $this->makeRelationDefinition(cascade: [CascadeType::Persist]);

        $this->assertTrue($rel->hasCascade(CascadeType::Persist));
        $this->assertFalse($rel->hasCascade(CascadeType::Remove));
    }

    public function test_relation_definition_has_cascade_returns_true_for_all(): void
    {
        $rel = $this->makeRelationDefinition(cascade: [CascadeType::All]);

        $this->assertTrue($rel->hasCascade(CascadeType::Persist));
        $this->assertTrue($rel->hasCascade(CascadeType::Remove));
        $this->assertTrue($rel->hasCascade(CascadeType::Detach));
    }

    public function test_relation_definition_has_cascade_returns_false_when_empty(): void
    {
        $rel = $this->makeRelationDefinition(cascade: []);

        $this->assertFalse($rel->hasCascade(CascadeType::Persist));
        $this->assertFalse($rel->hasCascade(CascadeType::Remove));
    }





    private function makeRelationDefinition(array $cascade): \Weaver\ORM\Mapping\RelationDefinition
    {
        return new \Weaver\ORM\Mapping\RelationDefinition(
            property: 'posts',
            type: \Weaver\ORM\Mapping\RelationType::HasMany,
            relatedEntity: 'App\Post',
            relatedMapper: 'App\PostMapper',
            cascade: $cascade,
        );
    }
}
