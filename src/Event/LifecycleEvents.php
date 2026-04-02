<?php

declare(strict_types=1);

namespace Weaver\ORM\Event;

final class LifecycleEvents
{

    public const BEFORE_ADD = 'weaver.before_add';

    public const AFTER_ADD = 'weaver.after_add';

    public const BEFORE_UPDATE = 'weaver.before_update';

    public const AFTER_UPDATE = 'weaver.after_update';

    public const BEFORE_DELETE = 'weaver.before_delete';

    public const AFTER_DELETE = 'weaver.after_delete';

    public const AFTER_LOAD = 'weaver.after_load';

    public const BEFORE_PUSH = 'weaver.before_push';

    public const AFTER_PUSH = 'weaver.after_push';

    public const ON_PUSH = 'weaver.on_push';

    public const ON_RESET = 'weaver.on_reset';

    private function __construct() {}
}
