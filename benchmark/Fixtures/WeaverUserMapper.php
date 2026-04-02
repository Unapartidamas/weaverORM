<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;

class WeaverUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return WeaverUser::class;
    }

    public function getTableName(): string
    {
        return 'bench_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id', 'id', 'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name', 'name', 'string', length: 255),
            new ColumnDefinition('email', 'email', 'string', length: 255),
            new ColumnDefinition('age', 'age', 'integer'),
            new ColumnDefinition('registered_at', 'registeredAt', 'string', length: 50),
        ];
    }
}
