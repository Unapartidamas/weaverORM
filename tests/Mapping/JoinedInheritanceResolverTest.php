<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\InheritanceMapping;
use Weaver\ORM\Mapping\JoinedInheritanceResolver;

final class JoinedInheritanceResolverTest extends TestCase
{
    private JoinedInheritanceResolver $resolver;
    private InheritanceMapping $mapping;

    protected function setUp(): void
    {
        $this->resolver = new JoinedInheritanceResolver();
        $this->mapping = new InheritanceMapping(
            type: InheritanceMapping::JOINED,
            discriminatorColumn: 'type',
            discriminatorType: 'string',
            discriminatorMap: [
                'employee' => 'App\\Entity\\Employee',
                'manager' => 'App\\Entity\\Manager',
            ],
            parentTable: 'people',
            childTables: [
                'App\\Entity\\Employee' => 'employees',
                'App\\Entity\\Manager' => 'managers',
            ],
            joinColumn: 'id',
        );
    }

    public function test_resolveSelectQuery_generates_join_between_parent_and_child(): void
    {
        $sql = $this->resolver->resolveSelectQuery('App\\Entity\\Employee', $this->mapping);

        self::assertStringContainsString('SELECT * FROM people', $sql);
        self::assertStringContainsString('INNER JOIN employees', $sql);
        self::assertStringContainsString('people.id = employees.id', $sql);
    }

    public function test_resolveSelectQuery_generates_left_joins_for_base_class(): void
    {
        $sql = $this->resolver->resolveSelectQuery('App\\Entity\\Person', $this->mapping);

        self::assertStringContainsString('SELECT * FROM people', $sql);
        self::assertStringContainsString('LEFT JOIN employees', $sql);
        self::assertStringContainsString('LEFT JOIN managers', $sql);
    }

    public function test_resolveInsertStatements_returns_parent_and_child_inserts(): void
    {
        $data = [
            'parent' => ['id' => 1, 'name' => 'John', 'type' => 'employee'],
            'child' => ['id' => 1, 'salary' => 50000],
        ];

        $statements = $this->resolver->resolveInsertStatements('App\\Entity\\Employee', $data, $this->mapping);

        self::assertCount(2, $statements);
        self::assertStringContainsString('INSERT INTO people', $statements[0]['sql']);
        self::assertStringContainsString('INSERT INTO employees', $statements[1]['sql']);
        self::assertSame([1, 'John', 'employee'], $statements[0]['params']);
        self::assertSame([1, 50000], $statements[1]['params']);
    }

    public function test_resolveDeleteStatements_deletes_child_then_parent(): void
    {
        $statements = $this->resolver->resolveDeleteStatements('App\\Entity\\Employee', 1, $this->mapping);

        self::assertCount(2, $statements);
        self::assertStringContainsString('DELETE FROM employees', $statements[0]['sql']);
        self::assertStringContainsString('DELETE FROM people', $statements[1]['sql']);
        self::assertSame([1], $statements[0]['params']);
        self::assertSame([1], $statements[1]['params']);
    }

    public function test_resolveUpdateStatements_routes_columns_correctly(): void
    {
        $data = [
            'parent' => ['name' => 'Jane'],
            'child' => ['salary' => 60000],
            'id' => 1,
        ];

        $statements = $this->resolver->resolveUpdateStatements('App\\Entity\\Employee', $data, $this->mapping);

        self::assertCount(2, $statements);
        self::assertStringContainsString('UPDATE people SET name = ?', $statements[0]['sql']);
        self::assertSame(['Jane', 1], $statements[0]['params']);
        self::assertStringContainsString('UPDATE employees SET salary = ?', $statements[1]['sql']);
        self::assertSame([60000, 1], $statements[1]['params']);
    }
}
