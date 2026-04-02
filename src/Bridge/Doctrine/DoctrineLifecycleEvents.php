<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Doctrine;

use Weaver\ORM\Event\LifecycleEvents;

final class DoctrineLifecycleEvents
{

    public const PRE_PERSIST = LifecycleEvents::BEFORE_ADD;

    public const POST_PERSIST = LifecycleEvents::AFTER_ADD;

    public const PRE_UPDATE = LifecycleEvents::BEFORE_UPDATE;

    public const POST_UPDATE = LifecycleEvents::AFTER_UPDATE;

    public const PRE_REMOVE = LifecycleEvents::BEFORE_DELETE;

    public const POST_REMOVE = LifecycleEvents::AFTER_DELETE;

    public const POST_LOAD = LifecycleEvents::AFTER_LOAD;

    public const PRE_FLUSH = LifecycleEvents::BEFORE_PUSH;

    public const POST_FLUSH = LifecycleEvents::AFTER_PUSH;

    public const ON_FLUSH = LifecycleEvents::ON_PUSH;

    public const ON_CLEAR = LifecycleEvents::ON_RESET;

    private function __construct() {}
}
