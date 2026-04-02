<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Relation\RelationLoader;
use Weaver\ORM\Tests\Fixture\Entity\User;
use Weaver\ORM\Tests\Fixture\Mapper\UserMapper;
use Weaver\ORM\Tests\Fixture\WeaverIntegrationTestCase;
use Weaver\ORM\PyroSQL\Approximate\ApproximateQueryBuilder;
use Weaver\ORM\PyroSQL\Query\TimeTravelQueryBuilder;
use Weaver\ORM\PyroSQL\PyroQueryBuilderExtension;

final class PyroQueryBuilderExtensionTest extends WeaverIntegrationTestCase
{






    private function makeExtension(): object
    {
        $connection     = $this->connection;
        $mapper         = new UserMapper();
        $hydrator       = $this->hydrator;
        $relationLoader = $this->relationLoader;

        return new class($connection, $mapper, $hydrator, $relationLoader) {
            use PyroQueryBuilderExtension;

            public function __construct(
                private readonly \Weaver\ORM\DBAL\Connection $conn,
                private readonly AbstractEntityMapper $mapper,
                private readonly EntityHydrator $hydrator,
                private readonly RelationLoader $relationLoader,
            ) {}



            protected function getConnection(): \Weaver\ORM\DBAL\Connection
            {
                return $this->conn;
            }

            protected function getMapper(): AbstractEntityMapper
            {
                return $this->mapper;
            }

            protected function getHydrator(): EntityHydrator
            {
                return $this->hydrator;
            }

            protected function getRelationLoader(): RelationLoader
            {
                return $this->relationLoader;
            }



            public function query(): EntityQueryBuilder
            {
                return new EntityQueryBuilder(
                    connection:     $this->conn,
                    entityClass:    User::class,
                    mapper:         $this->mapper,
                    hydrator:       $this->hydrator,
                    relationLoader: $this->relationLoader,
                );
            }
        };
    }





    public function test_query_as_of_returns_time_travel_query_builder(): void
    {
        $ext = $this->makeExtension();

        $ttqb = $ext->queryAsOf(new \DateTimeImmutable('2024-06-01 00:00:00'));

        self::assertInstanceOf(TimeTravelQueryBuilder::class, $ttqb);
    }

    public function test_query_as_of_configures_timestamp_expression(): void
    {
        $ext       = $this->makeExtension();
        $timestamp = new \DateTimeImmutable('2024-06-01 12:30:00');

        $ttqb = $ext->queryAsOf($timestamp);

        self::assertStringContainsString(
            "AS OF TIMESTAMP '2024-06-01 12:30:00'",
            $ttqb->getAsOfExpression(),
        );
    }

    public function test_query_as_of_with_different_timestamps_are_independent(): void
    {
        $ext = $this->makeExtension();

        $ttqbA = $ext->queryAsOf(new \DateTimeImmutable('2023-01-01 00:00:00'));
        $ttqbB = $ext->queryAsOf(new \DateTimeImmutable('2024-12-31 23:59:59'));

        self::assertNotSame($ttqbA, $ttqbB);
        self::assertStringContainsString('2023-01-01', $ttqbA->getAsOfExpression());
        self::assertStringContainsString('2024-12-31', $ttqbB->getAsOfExpression());
    }





    public function test_approximate_returns_approximate_query_builder(): void
    {
        $ext = $this->makeExtension();

        $aqb = $ext->approximate();

        self::assertInstanceOf(ApproximateQueryBuilder::class, $aqb);
    }

    public function test_approximate_uses_default_within_and_confidence(): void
    {
        $ext = $this->makeExtension();




        $mockConn = $this->createMock(\Weaver\ORM\DBAL\Connection::class);

        $mockConn->method('createQueryBuilder')
            ->willReturnCallback(fn () => $this->connection->createQueryBuilder());

        $capturedSql = '';
        $mockConn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;

                return false;
            });

        $mapper         = new UserMapper();
        $hydrator       = $this->hydrator;
        $relationLoader = $this->relationLoader;
        $conn           = $mockConn;

        $extWithMock = new class($conn, $mapper, $hydrator, $relationLoader) {
            use PyroQueryBuilderExtension;

            public function __construct(
                private readonly \Weaver\ORM\DBAL\Connection $conn,
                private readonly AbstractEntityMapper $mapper,
                private readonly EntityHydrator $hydrator,
                private readonly RelationLoader $relationLoader,
            ) {}

            protected function getConnection(): \Weaver\ORM\DBAL\Connection
            {
                return $this->conn;
            }

            protected function getMapper(): AbstractEntityMapper
            {
                return $this->mapper;
            }

            protected function getHydrator(): EntityHydrator
            {
                return $this->hydrator;
            }

            protected function getRelationLoader(): RelationLoader
            {
                return $this->relationLoader;
            }

            public function query(): EntityQueryBuilder
            {
                return new EntityQueryBuilder(
                    connection:     $this->conn,
                    entityClass:    User::class,
                    mapper:         $this->mapper,
                    hydrator:       $this->hydrator,
                    relationLoader: $this->relationLoader,
                );
            }
        };

        $extWithMock->approximate()->count();

        self::assertStringContainsString('WITHIN 5%', $capturedSql);
        self::assertStringContainsString('CONFIDENCE 95%', $capturedSql);
    }

    public function test_approximate_with_custom_within_and_confidence(): void
    {

        $mockConn = $this->createMock(\Weaver\ORM\DBAL\Connection::class);

        $mockConn->method('createQueryBuilder')
            ->willReturnCallback(fn () => $this->connection->createQueryBuilder());

        $capturedSql = '';
        $mockConn->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;

                return false;
            });

        $mapper         = new UserMapper();
        $hydrator       = $this->hydrator;
        $relationLoader = $this->relationLoader;
        $conn           = $mockConn;

        $extWithMock = new class($conn, $mapper, $hydrator, $relationLoader) {
            use PyroQueryBuilderExtension;

            public function __construct(
                private readonly \Weaver\ORM\DBAL\Connection $conn,
                private readonly AbstractEntityMapper $mapper,
                private readonly EntityHydrator $hydrator,
                private readonly RelationLoader $relationLoader,
            ) {}

            protected function getConnection(): \Weaver\ORM\DBAL\Connection
            {
                return $this->conn;
            }

            protected function getMapper(): AbstractEntityMapper
            {
                return $this->mapper;
            }

            protected function getHydrator(): EntityHydrator
            {
                return $this->hydrator;
            }

            protected function getRelationLoader(): RelationLoader
            {
                return $this->relationLoader;
            }

            public function query(): EntityQueryBuilder
            {
                return new EntityQueryBuilder(
                    connection:     $this->conn,
                    entityClass:    User::class,
                    mapper:         $this->mapper,
                    hydrator:       $this->hydrator,
                    relationLoader: $this->relationLoader,
                );
            }
        };

        $extWithMock->approximate(within: 1.0, confidence: 99.0)->count();

        self::assertStringContainsString('WITHIN 1%', $capturedSql);
        self::assertStringContainsString('CONFIDENCE 99%', $capturedSql);
    }

    public function test_approximate_instances_are_independent(): void
    {
        $ext = $this->makeExtension();

        $aqb1 = $ext->approximate(within: 2.0, confidence: 90.0);
        $aqb2 = $ext->approximate(within: 5.0, confidence: 95.0);

        self::assertNotSame($aqb1, $aqb2);
    }
}
