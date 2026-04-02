<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter\Filter;

use Weaver\ORM\Bridge\CodeIgniter\WeaverService;

final class WeaverResetFilter
{
    public function before($request, $arguments = null)
    {
        return null;
    }

    public function after($request, $response, $arguments = null)
    {
        WeaverService::workspaceRegistry()->resetAll();

        return $response;
    }
}
