<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;

class BenchUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return BenchUser::class;
    }

    public function getTableName(): string
    {
        return 'bench_users';
    }

    /** @return ColumnDefinition[] */
    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 255),
            new ColumnDefinition('email',  'email',  'string',  length: 255),
            new ColumnDefinition('age',    'age',    'integer'),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
        ];
    }
}
