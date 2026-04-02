<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

class CteUser
{
    public ?int $id     = null;
    public string $name  = '';
    public string $status = '';
}

class CteUserMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return CteUser::class;
    }

    public function getTableName(): string
    {
        return 'cte_users';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',     'id',     'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',   'name',   'string',  length: 100),
            new ColumnDefinition('status', 'status', 'string',  length: 50),
        ];
    }
}

class CteCategory
{
    public ?int $id       = null;
    public string $name    = '';
    public ?int $parentId = null;
}

class CteCategoryMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return CteCategory::class;
    }

    public function getTableName(): string
    {
        return 'cte_categories';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',        'id',        'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',      'name',      'string',  length: 100),
            new ColumnDefinition('parent_id', 'parentId',  'integer', nullable: true),
        ];
    }
}

final class CteTest extends TestCase
{
    private \Weaver\ORM\DBAL\Connection $connection;
    private MapperRegistry $registry;
    private EntityHydrator $hydrator;
    private CteUserMapper $userMapper;
    private CteCategoryMapper $categoryMapper;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE cte_users (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT    NOT NULL DEFAULT \'\',
                status TEXT    NOT NULL DEFAULT \'\'
            )'
        );

        $this->connection->executeStatement(
            'CREATE TABLE cte_categories (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL DEFAULT \'\',
                parent_id INTEGER NULL
            )'
        );

        $this->registry       = new MapperRegistry();
        $this->userMapper     = new CteUserMapper();
        $this->categoryMapper = new CteCategoryMapper();
        $this->registry->register($this->userMapper);
        $this->registry->register($this->categoryMapper);
        $this->hydrator = new EntityHydrator($this->registry, $this->connection);
    }





    private function makeUserQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            CteUser::class,
            $this->userMapper,
            $this->hydrator,
        );
    }

    private function makeCategoryQb(): EntityQueryBuilder
    {
        return new EntityQueryBuilder(
            $this->connection,
            CteCategory::class,
            $this->categoryMapper,
            $this->hydrator,
        );
    }

    private function seedUsers(): void
    {
        $this->connection->insert('cte_users', ['name' => 'Alice',   'status' => 'active']);
        $this->connection->insert('cte_users', ['name' => 'Bob',     'status' => 'inactive']);
        $this->connection->insert('cte_users', ['name' => 'Charlie', 'status' => 'active']);
        $this->connection->insert('cte_users', ['name' => 'Dave',    'status' => 'banned']);
    }

    private function seedCategories(): void
    {

        $this->connection->insert('cte_categories', ['name' => 'Root',       'parent_id' => null]);

        $this->connection->insert('cte_categories', ['name' => 'Child A',    'parent_id' => 1]);
        $this->connection->insert('cte_categories', ['name' => 'Child B',    'parent_id' => 1]);

        $this->connection->insert('cte_categories', ['name' => 'Grandchild', 'parent_id' => 2]);
    }





    public function test_simple_cte_returns_hydrated_entities(): void
    {
        $this->seedUsers();

        $result = $this->makeUserQb()
            ->withCte('active_users', 'SELECT * FROM cte_users WHERE status = :cte_status', ['cte_status' => 'active'])
            ->fromCte('active_users')
            ->get();

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(2, $result);

        foreach ($result as $user) {
            self::assertInstanceOf(CteUser::class, $user);
            self::assertSame('active', $user->status);
        }
    }





    public function test_cte_used_in_where_join(): void
    {
        $this->seedUsers();


        $result = $this->makeUserQb()
            ->withCte('active_ids', 'SELECT id FROM cte_users WHERE status = :act_status', ['act_status' => 'active'])
            ->whereRaw('e.id IN (SELECT id FROM active_ids)')
            ->get();

        self::assertCount(2, $result);

        foreach ($result as $user) {
            self::assertSame('active', $user->status);
        }
    }





    public function test_multiple_ctes_chained(): void
    {
        $this->seedUsers();

        $result = $this->makeUserQb()
            ->withCte(
                'active_users',
                'SELECT * FROM cte_users WHERE status = :multi_status',
                ['multi_status' => 'active'],
            )
            ->withCte(
                'named_a',
                "SELECT * FROM active_users WHERE name LIKE :name_prefix",
                ['name_prefix' => 'A%'],
            )
            ->fromCte('named_a')
            ->get();

        self::assertCount(1, $result);
        self::assertSame('Alice', $result->first()->name);
    }





    public function test_cte_with_closure_receives_dbal_qb(): void
    {
        $this->seedUsers();

        $result = $this->makeUserQb()
            ->withCte('inactive_users', function (\Weaver\ORM\DBAL\QueryBuilder $qb): void {
                $qb->select('*')
                    ->from('cte_users')
                    ->where('status = :cl_status')
                    ->setParameter('cl_status', 'inactive');
            })
            ->fromCte('inactive_users')
            ->get();

        self::assertCount(1, $result);
        self::assertSame('Bob', $result->first()->name);
        self::assertSame('inactive', $result->first()->status);
    }





    public function test_recursive_cte_for_hierarchical_data(): void
    {
        $this->seedCategories();


        $anchorSql    = 'SELECT id, name, parent_id FROM cte_categories WHERE id = 1';
        $recursiveSql = 'SELECT c.id, c.name, c.parent_id FROM cte_categories c INNER JOIN cat_tree ct ON ct.id = c.parent_id';

        $result = $this->makeCategoryQb()
            ->withRecursiveCte('cat_tree', $anchorSql, $recursiveSql)
            ->fromCte('cat_tree')
            ->get();


        self::assertCount(4, $result);

        $names = array_map(static fn (object $c): string => $c->name, iterator_to_array($result));
        self::assertContains('Root',       $names);
        self::assertContains('Child A',    $names);
        self::assertContains('Child B',    $names);
        self::assertContains('Grandchild', $names);
    }





    public function test_from_cte_sets_from_without_quoting(): void
    {
        $this->seedUsers();

        $qb = $this->makeUserQb()
            ->withCte('my_cte', 'SELECT * FROM cte_users WHERE status = :q_status', ['q_status' => 'active'])
            ->fromCte('my_cte');

        $sql = $qb->toSQL();


        self::assertStringContainsString('FROM my_cte', $sql);
        self::assertStringNotContainsString('FROM `my_cte`',  $sql);
        self::assertStringNotContainsString('FROM "my_cte"',  $sql);
        self::assertStringNotContainsString('FROM [my_cte]',  $sql);


        $result = $qb->get();
        self::assertCount(2, $result);
    }
}
