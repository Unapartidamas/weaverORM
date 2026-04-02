<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Fixture\Mapper;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;
use Weaver\ORM\Tests\Fixture\Entity\Profile;
use Weaver\ORM\Tests\Fixture\Entity\User;

class ProfileMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Profile::class;
    }

    public function getTableName(): string
    {
        return 'profiles';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('user_id', 'userId', 'integer', nullable: true),
            new ColumnDefinition('bio',     'bio',    'string',  length: 500),
        ];
    }

    public function getRelations(): array
    {
        return [
            new RelationDefinition('user', RelationType::BelongsTo, User::class, UserMapper::class, foreignKey: 'user_id'),
        ];
    }
}
