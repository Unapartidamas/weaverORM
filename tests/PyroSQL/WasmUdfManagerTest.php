<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\PyroSqlDriver;
use Weaver\ORM\PyroSQL\WasmUdf\WasmUdfManager;

final class WasmUdfManagerTest extends TestCase
{






    private function makeConnection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);


        $connection->method('fetchAssociative')
            ->willReturn(['v' => '2.0.0']);

        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');

        $connection->method('quote')
            ->willReturnCallback(static fn(mixed $v): string => "'" . addslashes((string) $v) . "'");

        return $connection;
    }



    private function makeDriver(Connection $connection): PyroSqlDriver
    {
        return new PyroSqlDriver($connection);
    }



    private function makeManager(): array
    {
        $connection = $this->makeConnection();
        $driver     = $this->makeDriver($connection);

        return [new WasmUdfManager($connection, $driver), $connection];
    }





    public function test_register_from_base64_executes_create_function_with_quoted_name(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('"sentiment_score"'));

        $manager->registerFromBase64('sentiment_score', base64_encode('fake-wasm'), 'FLOAT', ['TEXT']);
    }

    public function test_register_from_base64_uses_create_or_replace_when_replace_is_true(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('CREATE OR REPLACE FUNCTION'));

        $manager->registerFromBase64('my_func', base64_encode('fake-wasm'), 'INT', [], replace: true);
    }

    public function test_register_from_base64_uses_create_function_without_replace_by_default(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->logicalAnd(
                $this->stringContains('CREATE FUNCTION'),
                $this->logicalNot($this->stringContains('OR REPLACE'))
            ));

        $manager->registerFromBase64('my_func', base64_encode('fake-wasm'), 'INT');
    }

    public function test_register_from_base64_throws_invalid_argument_exception_for_invalid_return_type(): void
    {
        [$manager] = $this->makeManager();

        $this->expectException(\InvalidArgumentException::class);


        $manager->registerFromBase64('my_func', base64_encode('fake-wasm'), 'TEXT; DROP TABLE users');
    }

    public function test_register_from_base64_throws_invalid_argument_exception_for_invalid_arg_type(): void
    {
        [$manager] = $this->makeManager();

        $this->expectException(\InvalidArgumentException::class);


        $manager->registerFromBase64('my_func', base64_encode('fake-wasm'), 'FLOAT', ['TEXT; DROP TABLE users']);
    }

    public function test_register_from_base64_includes_language_wasm_in_sql(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('LANGUAGE wasm'));

        $manager->registerFromBase64('fn', base64_encode('wasm'), 'TEXT');
    }

    public function test_register_from_base64_includes_returns_clause_in_sql(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('RETURNS FLOAT'));

        $manager->registerFromBase64('fn', base64_encode('wasm'), 'FLOAT', ['TEXT']);
    }





    public function test_register_from_file_throws_runtime_exception_if_file_does_not_exist(): void
    {
        [$manager] = $this->makeManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot read WASM file/');

        $manager->registerFromFile('my_func', '/nonexistent/path/function.wasm', 'FLOAT', ['TEXT']);
    }





    public function test_list_returns_result_of_fetch_all_associative(): void
    {
        $connection = $this->makeConnection();
        $driver     = $this->makeDriver($connection);

        $expected = [
            ['name' => 'sentiment_score', 'return_type' => 'FLOAT', 'arg_types' => 'TEXT', 'created_at' => '2026-01-01T00:00:00Z'],
            ['name' => 'token_count',     'return_type' => 'INT',   'arg_types' => 'TEXT', 'created_at' => '2026-01-02T00:00:00Z'],
        ];


        $connection->method('fetchAllAssociative')->willReturn($expected);

        $manager = new WasmUdfManager($connection, $driver);

        self::assertSame($expected, $manager->list());
    }

    public function test_list_returns_empty_array_when_no_functions_registered(): void
    {
        $connection = $this->makeConnection();
        $driver     = $this->makeDriver($connection);

        $connection->method('fetchAllAssociative')->willReturn([]);

        $manager = new WasmUdfManager($connection, $driver);

        self::assertSame([], $manager->list());
    }





    public function test_exists_returns_true_when_row_is_found(): void
    {
        $connection = $this->makeConnection();
        $driver     = $this->makeDriver($connection);



        $connection->method('fetchAssociative')->willReturn(['v' => '2.0.0']);

        $manager = new WasmUdfManager($connection, $driver);

        self::assertTrue($manager->exists('sentiment_score'));
    }

    public function test_exists_returns_false_when_no_row_is_found(): void
    {
        $connection = $this->createMock(Connection::class);



        $connection->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['v' => '2.0.0'], false);

        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        $connection->method('quote')
            ->willReturnCallback(static fn(mixed $v): string => "'" . addslashes((string) $v) . "'");

        $driver  = new PyroSqlDriver($connection);
        $manager = new WasmUdfManager($connection, $driver);

        self::assertFalse($manager->exists('nonexistent_func'));
    }





    public function test_drop_executes_drop_function_with_quoted_name(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('DROP FUNCTION "sentiment_score"');

        $manager->drop('sentiment_score');
    }





    public function test_drop_if_exists_executes_drop_function_if_exists_with_quoted_name(): void
    {
        [$manager, $connection] = $this->makeManager();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('DROP FUNCTION IF EXISTS "sentiment_score"');

        $manager->dropIfExists('sentiment_score');
    }





    public function test_assert_supports_does_not_throw_when_driver_detects_pyrosql(): void
    {
        $connection = $this->createMock(Connection::class);


        $connection->method('fetchAssociative')->willReturn(['v' => '2.0.0']);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $driver = new PyroSqlDriver($connection);


        $driver->assertSupports('wasm_udfs');

        $this->addToAssertionCount(1);
    }

    public function test_register_from_base64_calls_assert_supports_implicitly_via_driver(): void
    {
        [$manager, $connection] = $this->makeManager();


        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('CREATE FUNCTION'));

        $manager->registerFromBase64('fn', base64_encode('wasm'), 'INT');
    }
}
