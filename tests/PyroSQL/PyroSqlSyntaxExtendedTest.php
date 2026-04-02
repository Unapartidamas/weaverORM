<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\PyroSqlSyntax;

final class PyroSqlSyntaxExtendedTest extends TestCase
{
    public function test_inspect(): void
    {
        self::assertSame('INSPECT users', PyroSqlSyntax::inspect('users'));
    }

    public function test_describe_is_alias_for_inspect(): void
    {
        self::assertSame(PyroSqlSyntax::inspect('users'), PyroSqlSyntax::describe('users'));
    }

    public function test_clear(): void
    {
        self::assertSame('CLEAR users', PyroSqlSyntax::clear('users'));
    }

    public function test_exportCsv(): void
    {
        self::assertSame(
            "EXPORT users TO CSV '/tmp/users.csv'",
            PyroSqlSyntax::exportCsv('users', '/tmp/users.csv'),
        );
    }

    public function test_explainVisual(): void
    {
        self::assertSame(
            'EXPLAIN VISUAL SELECT * FROM users WHERE age > 30',
            PyroSqlSyntax::explainVisual('SELECT * FROM users WHERE age > 30'),
        );
    }

    public function test_suggest(): void
    {
        self::assertSame(
            'SUGGEST INDEXES FOR orders',
            PyroSqlSyntax::suggest('orders'),
        );
    }

    public function test_tryIndex(): void
    {
        self::assertSame(
            'TRY INDEX ON orders(status)',
            PyroSqlSyntax::tryIndex('orders', 'status'),
        );
    }

    public function test_onBranch(): void
    {
        self::assertSame(
            'SELECT * FROM users ON BRANCH staging',
            PyroSqlSyntax::onBranch('staging', 'SELECT * FROM users'),
        );
    }

    public function test_history(): void
    {
        self::assertSame(
            "HISTORY users FROM '2025-06-01' TO '2025-06-30'",
            PyroSqlSyntax::history('users', '2025-06-01', '2025-06-30'),
        );
    }

    public function test_diff(): void
    {
        self::assertSame(
            "DIFF users BETWEEN 'main' AND 'staging'",
            PyroSqlSyntax::diff('users', 'main', 'staging'),
        );
    }

    public function test_subscribe(): void
    {
        self::assertSame(
            'SUBSCRIBE TO CHANGES ON orders',
            PyroSqlSyntax::subscribe('orders'),
        );
    }

    public function test_allow(): void
    {
        self::assertSame(
            'ALLOW reader TO READ users',
            PyroSqlSyntax::allow('reader', 'READ', 'users'),
        );
    }
}
