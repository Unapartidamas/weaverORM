<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Facade;

use Illuminate\Support\Facades\Facade;
use Weaver\ORM\Manager\EntityWorkspace;

/**
 * @method static void push(?object $entity = null)
 * @method static void upsert(object $entity)
 * @method static void reload(object $entity)
 * @method static void untrack(object $entity)
 * @method static object merge(object $entity)
 * @method static bool isTracked(object $entity)
 * @method static bool isDirty(object $entity)
 * @method static bool isNew(object $entity)
 * @method static bool isManaged(object $entity)
 * @method static bool isDeleted(object $entity)
 * @method static array getChanges(object $entity)
 * @method static void addBatch(array $entities)
 * @method static int pushBatch()
 * @method static \Weaver\ORM\Repository\EntityRepository getRepository(string $entityClass, string $repositoryClass = \Weaver\ORM\Repository\EntityRepository::class)
 *
 * @see EntityWorkspace
 */
final class Weaver extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntityWorkspace::class;
    }
}
