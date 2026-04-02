<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;

class BenchPostMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return BenchPost::class;
    }

    public function getTableName(): string
    {
        return 'bench_posts';
    }

    /** @return ColumnDefinition[] */
    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',      'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('user_id', 'userId', 'integer'),
            new ColumnDefinition('title',   'title',  'string',  length: 255),
            new ColumnDefinition('body',    'body',   'string',  length: 1000),
        ];
    }
}
