<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Scenarios;

use Weaver\ORM\DBAL\Connection;
use Doctrine\ORM\EntityManager;

interface BenchScenario
{
    public function name(): string;

    public function setup(Connection $conn): void;

    /** Runs $iterations of the Weaver ORM path and returns ops/sec. */
    public function runWeaver(Connection $conn, int $iterations): float;

    /** Runs $iterations of the Doctrine ORM EntityManager path and returns ops/sec. */
    public function runDoctrine(EntityManager $em, int $iterations): float;

    public function teardown(Connection $conn): void;
}
