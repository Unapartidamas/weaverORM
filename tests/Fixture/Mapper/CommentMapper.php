<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Mapper;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Comment;
use Weaver\ORM\Tests\Fixture\Entity\Post;

class CommentMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Comment::class;
    }

    public function getTableName(): string
    {
        return 'comments';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('post_id', 'postId', 'integer', nullable: true),
            new ColumnDefinition('body',    'body',   'string',  length: 500),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition('post', RelationType::BelongsTo, Post::class, PostMapper::class, foreignKey: 'post_id'),
        ];
    }
}
