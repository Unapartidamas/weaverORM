<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\DBAL;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\DBAL\QueryBuilder;

final class QueryBuilderTest extends TestCase
{
    #[Test]
    public function test_simple_select(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users', 'u');

        self::assertSame('SELECT * FROM users u', $qb->getSQL());
    }

    #[Test]
    public function test_select_with_where(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->where('id = ?', [1]);

        self::assertSame('SELECT * FROM users WHERE id = ?', $qb->getSQL());
        self::assertSame([1], $qb->getParameters());
    }

    #[Test]
    public function test_select_with_multiple_wheres(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->where('active = ?', [1])
            ->where('role = ?', ['admin']);

        self::assertSame('SELECT * FROM users WHERE active = ? AND role = ?', $qb->getSQL());
        self::assertSame([1, 'admin'], $qb->getParameters());
    }

    #[Test]
    public function test_select_with_or_where(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->where('active = ?', [1])
            ->orWhere('role = ?', ['admin']);

        self::assertSame('SELECT * FROM users WHERE active = ? OR role = ?', $qb->getSQL());
        self::assertSame([1, 'admin'], $qb->getParameters());
    }

    #[Test]
    public function test_select_with_order_by(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->orderBy('name', 'ASC')
            ->addOrderBy('created_at', 'DESC');

        self::assertSame('SELECT * FROM users ORDER BY name ASC, created_at DESC', $qb->getSQL());
    }

    #[Test]
    public function test_select_with_limit_offset(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->setMaxResults(10)
            ->setFirstResult(20);

        self::assertSame('SELECT * FROM users LIMIT 10 OFFSET 20', $qb->getSQL());
    }

    #[Test]
    public function test_select_with_join(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users', 'u')
            ->join('orders', 'o', 'o.user_id = u.id');

        self::assertSame(
            'SELECT * FROM users u INNER JOIN orders o ON o.user_id = u.id',
            $qb->getSQL()
        );
    }

    #[Test]
    public function test_select_with_left_join(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users', 'u')
            ->leftJoin('profiles', 'p', 'p.user_id = u.id');

        self::assertSame(
            'SELECT * FROM users u LEFT JOIN profiles p ON p.user_id = u.id',
            $qb->getSQL()
        );
    }

    #[Test]
    public function test_select_with_group_by_having(): void
    {
        $qb = new QueryBuilder();
        $qb->select('status', 'COUNT(*) as cnt')
            ->from('orders')
            ->groupBy('status')
            ->having('COUNT(*) > ?', [5]);

        self::assertSame(
            'SELECT status, COUNT(*) as cnt FROM orders GROUP BY status HAVING COUNT(*) > ?',
            $qb->getSQL()
        );
        self::assertSame([5], $qb->getParameters());
    }

    #[Test]
    public function test_select_distinct(): void
    {
        $qb = new QueryBuilder();
        $qb->distinct()
            ->select('email')
            ->from('users');

        self::assertSame('SELECT DISTINCT email FROM users', $qb->getSQL());
    }

    #[Test]
    public function test_insert_builds_correct_sql(): void
    {
        $qb = new QueryBuilder();
        $qb->insert('users')
            ->setValue('name', '?')
            ->setValue('email', '?');

        self::assertSame(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            $qb->getSQL()
        );
    }

    #[Test]
    public function test_update_builds_correct_sql(): void
    {
        $qb = new QueryBuilder();
        $qb->update('users')
            ->set('name', '?')
            ->set('email', '?')
            ->where('id = ?', [1]);

        self::assertSame(
            'UPDATE users SET name = ?, email = ? WHERE id = ?',
            $qb->getSQL()
        );
        self::assertSame([1], $qb->getParameters());
    }

    #[Test]
    public function test_delete_builds_correct_sql(): void
    {
        $qb = new QueryBuilder();
        $qb->delete('users')
            ->where('id = ?', [1]);

        self::assertSame('DELETE FROM users WHERE id = ?', $qb->getSQL());
        self::assertSame([1], $qb->getParameters());
    }

    #[Test]
    public function test_parameters_collected_correctly(): void
    {
        $qb = new QueryBuilder();
        $qb->from('users')
            ->where('status = ?', ['active'])
            ->where('age > ?', [18])
            ->orWhere('role = ?', ['admin']);

        self::assertSame(['active', 18, 'admin'], $qb->getParameters());
        self::assertSame([], $qb->getParameterTypes());
    }

    #[Test]
    public function test_complex_query_builds_correctly(): void
    {
        $qb = new QueryBuilder();
        $qb->select('u.id', 'u.name', 'COUNT(o.id) as order_count')
            ->from('users', 'u')
            ->leftJoin('orders', 'o', 'o.user_id = u.id')
            ->where('u.active = ?', [1])
            ->orWhere('u.role = ?', ['vip'])
            ->groupBy('u.id', 'u.name')
            ->having('COUNT(o.id) > ?', [0])
            ->orderBy('order_count', 'DESC')
            ->setMaxResults(10)
            ->setFirstResult(0);

        $expected = 'SELECT u.id, u.name, COUNT(o.id) as order_count'
            . ' FROM users u'
            . ' LEFT JOIN orders o ON o.user_id = u.id'
            . ' WHERE u.active = ? OR u.role = ?'
            . ' GROUP BY u.id, u.name'
            . ' HAVING COUNT(o.id) > ?'
            . ' ORDER BY order_count DESC'
            . ' LIMIT 10';

        self::assertSame($expected, $qb->getSQL());
        self::assertSame([1, 'vip', 0], $qb->getParameters());
    }
}
