<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Weaver\ORM\Manager\WorkspaceRegistry;

final class WeaverResetMiddleware
{
    public function __construct(
        private readonly WorkspaceRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->registry->resetAll();
    }
}
