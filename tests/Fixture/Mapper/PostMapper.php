<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Mapper;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Comment;
use Weaver\ORM\Tests\Fixture\Entity\Post;
use Weaver\ORM\Tests\Fixture\Entity\User;

class PostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Post::class;
    }

    public function getTableName(): string
    {
        return 'posts';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',         'id',        'integer',            primary: true, autoIncrement: true),
            new ColumnDefinition('title',      'title',     'string',             length: 255),
            new ColumnDefinition('status',     'status',    'string',             length: 20),
            new ColumnDefinition('user_id',    'userId',    'integer',            nullable: true),
            new ColumnDefinition('deleted_at', 'deletedAt', 'datetime_immutable', nullable: true),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition('user',     RelationType::BelongsTo, User::class,    UserMapper::class,    foreignKey: 'user_id'),
            new RelationDefinition('comments', RelationType::HasMany,   Comment::class, CommentMapper::class, foreignKey: 'post_id'),
        ];
    }
}
