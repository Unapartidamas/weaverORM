<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Mapper;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Post;
use Weaver\ORM\Tests\Fixture\Entity\Profile;
use Weaver\ORM\Tests\Fixture\Entity\User;

class UserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return User::class;
    }

    public function getTableName(): string
    {
        return 'users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',        'id',        'integer',            primary: true, autoIncrement: true),
            new ColumnDefinition('email',      'email',     'string',             length: 180),
            new ColumnDefinition('name',       'name',      'string',             length: 100),
            new ColumnDefinition('role',       'role',      'string',             length: 20),
            new ColumnDefinition('active',     'active',    'boolean'),
            new ColumnDefinition('created_at', 'createdAt', 'datetime_immutable', nullable: true),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition('posts',   RelationType::HasMany, Post::class,    PostMapper::class,    foreignKey: 'user_id'),
            new RelationDefinition('profile', RelationType::HasOne,  Profile::class, ProfileMapper::class, foreignKey: 'user_id'),
        ];
    }
}
