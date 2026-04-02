<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter;

class WeaverConfig
{
    public string $defaultConnection = 'default';

    public array $connections = [
        'default' => [
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ];

    public bool $debug = false;

    public bool $n1Detector = false;

    public int $maxRowsSafetyLimit = 100000;

    public array $mapperDirectories = [];

    public array $cache = [
        'enabled' => false,
        'adapter' => null,
        'defaultTtl' => 3600,
    ];
}
