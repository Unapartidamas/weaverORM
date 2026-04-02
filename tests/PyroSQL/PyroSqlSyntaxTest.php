<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\PyroSQL\PyroSqlSyntax;

final class PyroSqlSyntaxTest extends TestCase
{
    public function test_find_generates_correct_sql(): void
    {
        self::assertSame('FIND users', PyroSqlSyntax::find('users'));
    }

    public function test_find_with_columns_generates_correct_sql(): void
    {
        self::assertSame(
            'FIND users.name, users.age',
            PyroSqlSyntax::find('users', ['name', 'age']),
        );
    }

    public function test_find_with_limit_and_sort(): void
    {
        self::assertSame(
            'FIND TOP 10 users SORT BY name',
            PyroSqlSyntax::find('users', ['*'], 10, 'name'),
        );
    }

    public function test_findUnique_generates_correct_sql(): void
    {
        self::assertSame(
            'FIND UNIQUE users.country',
            PyroSqlSyntax::findUnique('users', 'country'),
        );
    }

    public function test_add_generates_correct_sql(): void
    {
        self::assertSame(
            "ADD users (name: 'Alice', age: 30)",
            PyroSqlSyntax::add('users', ['name' => 'Alice', 'age' => 30]),
        );
    }

    public function test_change_generates_correct_sql(): void
    {
        self::assertSame(
            "CHANGE users SET age = 31 WHERE name = 'Alice'",
            PyroSqlSyntax::change('users', ['age' => 31], "name = 'Alice'"),
        );
    }

    public function test_remove_generates_correct_sql(): void
    {
        self::assertSame(
            'REMOVE users WHERE age < 18',
            PyroSqlSyntax::remove('users', 'age < 18'),
        );
    }

    public function test_count_generates_correct_sql(): void
    {
        self::assertSame('COUNT users', PyroSqlSyntax::count('users'));
    }

    public function test_sample_generates_correct_sql(): void
    {
        self::assertSame(
            'SAMPLE 10 FROM users',
            PyroSqlSyntax::sample('users', 10),
        );
    }

    public function test_nearest_generates_correct_sql(): void
    {
        self::assertSame(
            "NEAREST 5 TO '[1,2,3]' FROM items",
            PyroSqlSyntax::nearest('items', 'embedding', [1.0, 2.0, 3.0], 5),
        );
    }

    public function test_search_generates_correct_sql(): void
    {
        self::assertSame(
            "SEARCH 'hello world' IN posts(body)",
            PyroSqlSyntax::search('posts', 'body', 'hello world'),
        );
    }

    public function test_upsert_generates_correct_sql(): void
    {
        self::assertSame(
            "UPSERT INTO users (name, age) VALUES ('Alice', 30) ON id",
            PyroSqlSyntax::upsert('users', ['name' => 'Alice', 'age' => 30], 'id'),
        );
    }

    public function test_importCsv_generates_correct_sql(): void
    {
        self::assertSame(
            "IMPORT CSV '/tmp/data.csv' INTO users",
            PyroSqlSyntax::importCsv('/tmp/data.csv', 'users'),
        );
    }

    public function test_protect_generates_correct_sql(): void
    {
        self::assertSame(
            "PROTECT users WHERE role = 'admin'",
            PyroSqlSyntax::protect('users', "role = 'admin'"),
        );
    }
}
