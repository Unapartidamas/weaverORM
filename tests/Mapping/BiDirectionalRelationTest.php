<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\Attribute\BelongsTo;
use Weaver\ORM\Mapping\Attribute\BelongsToMany;
use Weaver\ORM\Mapping\Attribute\HasMany;
use Weaver\ORM\Mapping\Attribute\HasOne;
use Weaver\ORM\Mapping\BiDirectionalLinker;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;

class BdPost
{
    public int $id = 0;
    public array $comments = [];
}

class BdComment
{
    public int $id = 0;
    public ?BdPost $post = null;
}

final class BiDirectionalRelationTest extends TestCase
{

    public function testHasManyStoresInversedBy(): void
    {
        $attr = new HasMany(
            target: BdComment::class,
            foreignKey: 'post_id',
            inversedBy: 'post',
        );

        self::assertSame('post', $attr->inversedBy);
        self::assertSame('', $attr->mappedBy);
    }


    public function testBelongsToStoresMappedBy(): void
    {
        $attr = new BelongsTo(
            target: BdPost::class,
            foreignKey: 'post_id',
            mappedBy: 'comments',
        );

        self::assertSame('comments', $attr->mappedBy);
        self::assertSame('', $attr->inversedBy);
    }


    public function testIsOwningSideReturnsTrueWhenInversedBySet(): void
    {
        $def = $this->makeRelationDefinition(inversedBy: 'post');

        self::assertTrue($def->isOwningSide());
        self::assertFalse($def->isInverseSide());
    }


    public function testIsInverseSideReturnsTrueWhenMappedBySet(): void
    {
        $def = $this->makeRelationDefinition(mappedBy: 'comments');

        self::assertTrue($def->isInverseSide());
        self::assertFalse($def->isOwningSide());
    }


    public function testIsBidirectional(): void
    {
        $owning  = $this->makeRelationDefinition(inversedBy: 'post');
        $inverse = $this->makeRelationDefinition(mappedBy: 'comments');
        $uni     = $this->makeRelationDefinition();

        self::assertTrue($owning->isBidirectional());
        self::assertTrue($inverse->isBidirectional());
        self::assertFalse($uni->isBidirectional());
    }


    public function testLinkCollectionSetsBackReference(): void
    {
        $post     = new BdPost();
        $post->id = 1;

        $c1     = new BdComment();
        $c1->id = 10;
        $c2     = new BdComment();
        $c2->id = 20;

        BiDirectionalLinker::linkCollection($post, [$c1, $c2], 'post');

        self::assertSame($post, $c1->post);
        self::assertSame($post, $c2->post);
    }


    public function testLinkSingleAddsOwnerToCollection(): void
    {
        $post     = new BdPost();
        $post->id = 1;

        $comment     = new BdComment();
        $comment->id = 10;

        BiDirectionalLinker::linkSingle($comment, $post, 'comments');

        self::assertContains($comment, $post->comments);
    }


    public function testLinkSingleNoDuplicate(): void
    {
        $post     = new BdPost();
        $post->id = 1;

        $comment          = new BdComment();
        $comment->id      = 10;
        $post->comments   = [$comment];

        BiDirectionalLinker::linkSingle($comment, $post, 'comments');

        self::assertCount(1, $post->comments);
    }


    public function testLinkCollectionSafeWithEmptyIterable(): void
    {
        $post     = new BdPost();
        $post->id = 1;


        BiDirectionalLinker::linkCollection($post, [], 'post');

        self::assertSame([], $post->comments);
    }


    public function testUnidirectionalRelationIsNeitherSide(): void
    {
        $def = $this->makeRelationDefinition();

        self::assertFalse($def->isOwningSide());
        self::assertFalse($def->isInverseSide());
        self::assertFalse($def->isBidirectional());
    }





    private function makeRelationDefinition(
        string $inversedBy = '',
        string $mappedBy = '',
    ): RelationDefinition {
        return new RelationDefinition(
            property:      'comments',
            type:          RelationType::HasMany,
            relatedEntity: BdComment::class,
            relatedMapper: 'SomeMapper',
            foreignKey:    'post_id',
            inversedBy:    $inversedBy,
            mappedBy:      $mappedBy,
        );
    }
}
