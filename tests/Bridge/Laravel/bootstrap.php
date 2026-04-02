<?php

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value !== false ? $value : $default;
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return '/tmp/app' . ($path !== '' ? '/' . $path : '');
    }
}
