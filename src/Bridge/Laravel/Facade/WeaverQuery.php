<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Facade;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Weaver\ORM\Query\EntityQueryBuilder query(string $entityClass)
 *
 * @see \Weaver\ORM\Bridge\Laravel\WeaverQueryFactory
 */
final class WeaverQuery extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'weaver.query_factory';
    }
}
