<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Bridge;

require_once __DIR__ . '/EquivalenceTest.php';

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\EntityQueryBuilder;

final class QueryEquivalenceTest extends TestCase
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER,
            score REAL,
            active INTEGER DEFAULT 1,
            bio TEXT,
            registered_at TEXT
        )
    SQL;

    private Connection $wConn;
    private Connection $dConn;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $this->wConn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->wConn->executeStatement(self::SCHEMA);

        $this->dConn = ConnectionFactory::create(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->dConn->executeStatement(self::SCHEMA);

        $this->registry = new MapperRegistry();
        $this->registry->register(new BenchUserMapper());

        $this->seed();
    }

    private function seed(): void
    {
        $names = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Hank', 'Ivy', 'Jack',
                   'Karen', 'Leo', 'Mona', 'Nate', 'Olivia', 'Paul', 'Quinn', 'Rita', 'Sam', 'Tina',
                   'Uma', 'Vince', 'Wendy', 'Xander', 'Yara', 'Zane', 'Amber', 'Blake', 'Cora', 'Drew',
                   'Ella', 'Finn', 'Gina', 'Hugo', 'Iris', 'Joel', 'Kira', 'Liam', 'Mia', 'Noah',
                   'Opal', 'Pete', 'Rosa', 'Sean', 'Tara', 'Uri', 'Vera', 'Will', 'Xena', 'Yuri'];

        $bios = [
            null, 'Developer', null, 'Designer', 'Manager',
            null, 'Ünïcödé ñ 日本語', 'Has a "quote"', null, "Line1\nLine2",
            'O\'Reilly fan', null, 'Enjoys 100% effort', null, 'Works at <Corp>',
            null, 'Bio with % wildcard', null, 'Regular bio', null,
            'Emoji lover', null, null, 'Tester', null,
            null, 'Writer', null, 'Artist', null,
            'Musician', null, null, 'Chef', null,
            null, 'Pilot', null, 'Nurse', null,
            'Teacher', null, null, 'Farmer', null,
            null, 'Actor', null, 'Doctor', null,
        ];

        $dates = [
            '2020-01-15', '2020-03-22', '2021-06-01', '2021-07-14', '2022-01-01',
            '2022-02-28', '2022-05-10', '2022-08-19', '2022-11-30', '2023-01-01',
            '2023-02-14', '2023-03-17', '2023-04-01', '2023-05-05', '2023-06-21',
            '2023-07-04', '2023-08-15', '2023-09-09', '2023-10-31', '2023-11-11',
            '2024-01-01', '2024-02-29', '2024-03-14', '2024-04-22', '2024-05-30',
            '2024-06-15', '2024-07-04', '2024-08-08', '2024-09-01', '2024-10-10',
            '2024-11-11', '2024-12-25', '2025-01-01', '2025-02-14', '2025-03-17',
            '2025-04-01', '2025-05-05', '2025-06-21', '2025-07-04', '2025-08-15',
            '2025-09-09', '2025-10-31', '2025-11-11', '2025-12-25', '2026-01-01',
            '2026-02-14', '2026-03-17', '2026-04-01', '2026-05-05', '2026-06-21',
        ];

        for ($i = 1; $i <= 50; $i++) {
            $row = [
                'name'          => $names[$i - 1],
                'email'         => 'user' . $i . '@test.com',
                'age'           => 18 + (($i * 7 + 3) % 63),
                'score'         => round(0.5 + (($i * 13.7) % 99.4), 1),
                'active'        => $i % 3 === 0 ? 0 : 1,
                'bio'           => $bios[$i - 1],
                'registered_at' => $dates[$i - 1],
            ];

            $this->wConn->insert('users', $row);
            $this->dConn->insert('users', $row);
        }
    }

    private function qb(): EntityQueryBuilder
    {
        $mapper   = $this->registry->get(BenchUser::class);
        $hydrator = new EntityHydrator($this->registry, $this->wConn);

        return new EntityQueryBuilder($this->wConn, BenchUser::class, $mapper, $hydrator);
    }

    private function dbalAll(string $sql, array $params = []): array
    {
        return $this->dConn->fetchAllAssociative($sql, $params);
    }

    private function dbalOne(string $sql, array $params = []): array|false
    {
        return $this->dConn->fetchAssociative($sql, $params);
    }

    private function dbalScalar(string $sql, array $params = []): mixed
    {
        return $this->dConn->fetchOne($sql, $params);
    }

    private function entityToArray(object $entity): array
    {
        return [
            'id'            => $entity->id,
            'name'          => $entity->name,
            'email'         => $entity->email,
            'age'           => $entity->age,
            'score'         => $entity->score,
            'active'        => $entity->active,
            'bio'           => $entity->bio,
            'registeredAt'  => $entity->registeredAt,
        ];
    }

    private function assertRowsMatch(array $dbalRows, array $entities, array $fields = ['id', 'name', 'email']): void
    {
        $fieldMap = [
            'id' => 'id', 'name' => 'name', 'email' => 'email',
            'age' => 'age', 'score' => 'score', 'active' => 'active',
            'bio' => 'bio', 'registered_at' => 'registeredAt',
        ];

        self::assertCount(count($dbalRows), $entities, 'Row count mismatch');

        for ($i = 0; $i < count($dbalRows); $i++) {
            foreach ($fields as $dbField) {
                $entityField = $fieldMap[$dbField] ?? $dbField;
                $dbalVal  = $dbalRows[$i][$dbField];
                $entVal   = $entities[$i]->$entityField ?? null;

                if ($dbField === 'score') {
                    self::assertEqualsWithDelta((float) $dbalVal, (float) $entVal, 0.01, "Row $i field $dbField");
                } elseif (in_array($dbField, ['id', 'age', 'active'], true)) {
                    self::assertSame((int) $dbalVal, (int) $entVal, "Row $i field $dbField");
                } else {
                    self::assertSame($dbalVal, $entVal, "Row $i field $dbField");
                }
            }
        }
    }

    // ──── Basic WHERE ────

    public function test_where_equals(): void
    {
        $entities = $this->qb()->where('name', 'Alice')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE name = ?', ['Alice']);
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_where_not_equals(): void
    {
        $entities = $this->qb()->where('name', '!=', 'Alice')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE name != ?', ['Alice']);
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_where_greater_than(): void
    {
        $entities = $this->qb()->where('age', '>', 50)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age > 50');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_where_less_than(): void
    {
        $entities = $this->qb()->where('age', '<', 25)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age < 25');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_where_greater_equal(): void
    {
        $entities = $this->qb()->where('age', '>=', 70)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age >= 70');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_where_less_equal(): void
    {
        $entities = $this->qb()->where('age', '<=', 20)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age <= 20');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_orWhere(): void
    {
        $entities = $this->qb()->where('name', 'Alice')->orWhere('name', 'Bob')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE name = ? OR name = ?', ['Alice', 'Bob']);
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_where_closure_nested(): void
    {
        $entities = $this->qb()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('age', '>', 40)->orWhere('age', '<', 25);
            })
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 AND (age > 40 OR age < 25)');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age', 'active']);
    }

    // ──── NULL handling ────

    public function test_whereNull(): void
    {
        $entities = $this->qb()->whereNull('bio')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE bio IS NULL');
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereNotNull(): void
    {
        $entities = $this->qb()->whereNotNull('bio')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE bio IS NOT NULL');
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    // ──── IN / NOT IN ────

    public function test_whereIn(): void
    {
        $entities = $this->qb()->whereIn('id', [1, 5, 10, 20, 50])->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE id IN (1, 5, 10, 20, 50)');
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(5, $entities);
    }

    public function test_whereIn_empty_array(): void
    {
        $entities = $this->qb()->whereIn('id', [])->get()->toArray();
        self::assertCount(0, $entities);
    }

    public function test_whereNotIn(): void
    {
        $entities = $this->qb()->whereNotIn('id', [1, 2, 3])->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE id NOT IN (1, 2, 3)');
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(47, $entities);
    }

    // ──── BETWEEN ────

    public function test_whereBetween(): void
    {
        $entities = $this->qb()->whereBetween('age', 30, 40)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age BETWEEN 30 AND 40');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereNotBetween(): void
    {
        $entities = $this->qb()->whereNotBetween('age', 30, 40)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age NOT BETWEEN 30 AND 40');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
        self::assertGreaterThan(0, count($entities));
    }

    // ──── LIKE ────

    public function test_whereLike(): void
    {
        $entities = $this->qb()->whereLike('name', 'A%')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name LIKE 'A%'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereNotLike(): void
    {
        $entities = $this->qb()->whereNotLike('name', 'A%')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name NOT LIKE 'A%'");
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_whereILike(): void
    {
        $entities = $this->qb()->whereILike('name', 'a%')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE LOWER(name) LIKE 'a%'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereStartsWith(): void
    {
        $entities = $this->qb()->whereStartsWith('name', 'Ch')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name LIKE 'Ch%'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereEndsWith(): void
    {
        $entities = $this->qb()->whereEndsWith('name', 'a')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name LIKE '%a'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereContains(): void
    {
        $entities = $this->qb()->whereContains('name', 'an')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name LIKE '%an%'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    // ──── Raw ────

    public function test_whereRaw(): void
    {
        $entities = $this->qb()->whereRaw('age > 60')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age > 60');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_orWhereRaw(): void
    {
        $entities = $this->qb()->where('name', 'Alice')->orWhereRaw('name = \'Bob\'')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name = 'Alice' OR name = 'Bob'");
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(2, $entities);
    }

    public function test_whereRaw_with_bindings(): void
    {
        $entities = $this->qb()->whereRaw('age > :min_age', ['min_age' => 60])->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE age > ?', [60]);
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    // ──── Ordering ────

    public function test_orderBy_asc(): void
    {
        $entities = $this->qb()->orderBy('name', 'ASC')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY name ASC');
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_orderBy_desc(): void
    {
        $entities = $this->qb()->orderByDesc('age')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY age DESC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    public function test_orderBy_multiple_columns(): void
    {
        $entities = $this->qb()->orderBy('active', 'ASC')->addOrderBy('name', 'ASC')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY active ASC, name ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'active']);
    }

    public function test_orderByRaw(): void
    {
        $entities = $this->qb()->orderByRaw('age DESC, name ASC')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY age DESC, name ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
    }

    // ──── Limit / Offset ────

    public function test_limit(): void
    {
        $entities = $this->qb()->orderBy('id')->limit(10)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY id ASC LIMIT 10');
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(10, $entities);
    }

    public function test_offset(): void
    {
        $entities = $this->qb()->orderBy('id')->offset(40)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY id ASC LIMIT -1 OFFSET 40');
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(10, $entities);
    }

    public function test_limit_and_offset_combined(): void
    {
        $entities = $this->qb()->orderBy('id')->limit(5)->offset(10)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY id ASC LIMIT 5 OFFSET 10');
        $this->assertRowsMatch($rows, $entities);
        self::assertCount(5, $entities);
        self::assertSame(11, $entities[0]->id);
    }

    // ──── Select ────

    public function test_select_specific_columns(): void
    {
        $entities = $this->qb()->select('name', 'email')->orderBy('id')->get()->toArray();
        $rows     = $this->dbalAll('SELECT name, email FROM users ORDER BY id ASC');
        self::assertCount(count($rows), $entities);
        for ($i = 0; $i < count($rows); $i++) {
            self::assertSame($rows[$i]['name'], $entities[$i]->name);
            self::assertSame($rows[$i]['email'], $entities[$i]->email);
        }
    }

    public function test_addSelect(): void
    {
        $entities = $this->qb()->select('name')->addSelect('age')->orderBy('id')->get()->toArray();
        $rows     = $this->dbalAll('SELECT name, age FROM users ORDER BY id ASC');
        self::assertCount(count($rows), $entities);
        for ($i = 0; $i < count($rows); $i++) {
            self::assertSame($rows[$i]['name'], $entities[$i]->name);
            self::assertSame((int) $rows[$i]['age'], $entities[$i]->age);
        }
    }

    public function test_selectRaw(): void
    {
        $entities = $this->qb()->select('name')->selectRaw('age * 2 AS double_age')->orderBy('id')->limit(5)->get()->toArray();
        $rows     = $this->dbalAll('SELECT name, age * 2 AS double_age FROM users ORDER BY id ASC LIMIT 5');
        self::assertCount(count($rows), $entities);
        for ($i = 0; $i < count($rows); $i++) {
            self::assertSame($rows[$i]['name'], $entities[$i]->name);
        }
    }

    public function test_distinct(): void
    {
        $entities = $this->qb()->select('active')->distinct()->orderBy('active')->get()->toArray();
        $rows     = $this->dbalAll('SELECT DISTINCT active FROM users ORDER BY active ASC');
        self::assertCount(count($rows), $entities);
        for ($i = 0; $i < count($rows); $i++) {
            self::assertSame((int) $rows[$i]['active'], $entities[$i]->active);
        }
    }

    // ──── Aggregates ────

    public function test_count_all(): void
    {
        $count    = $this->qb()->count();
        $dbalCount = (int) $this->dbalScalar('SELECT COUNT(*) FROM users');
        self::assertSame($dbalCount, $count);
        self::assertSame(50, $count);
    }

    public function test_count_with_where(): void
    {
        $count    = $this->qb()->where('active', 1)->count();
        $dbalCount = (int) $this->dbalScalar('SELECT COUNT(*) FROM users WHERE active = 1');
        self::assertSame($dbalCount, $count);
        self::assertGreaterThan(0, $count);
    }

    public function test_exists_true(): void
    {
        $exists = $this->qb()->where('name', 'Alice')->exists();
        self::assertTrue($exists);
    }

    public function test_exists_false(): void
    {
        $exists = $this->qb()->where('name', 'NonExistent')->exists();
        self::assertFalse($exists);
    }

    // ──── Result methods ────

    public function test_get_returns_all_matching(): void
    {
        $entities = $this->qb()->where('active', 1)->orderBy('id')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE active = 1 ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_first_returns_first_row(): void
    {
        $entity = $this->qb()->orderBy('id')->first();
        $row    = $this->dbalOne('SELECT * FROM users ORDER BY id ASC LIMIT 1');
        self::assertNotNull($entity);
        self::assertNotFalse($row);
        self::assertSame((int) $row['id'], $entity->id);
        self::assertSame($row['name'], $entity->name);
    }

    public function test_first_returns_null_when_empty(): void
    {
        $entity = $this->qb()->where('name', 'NonExistentUser999')->first();
        self::assertNull($entity);
    }

    public function test_firstOrFail_throws_when_empty(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->qb()->where('name', 'NonExistentUser999')->firstOrFail();
    }

    // ──── Subqueries ────

    public function test_whereSubquery(): void
    {
        $entities = $this->qb()
            ->whereSubquery('age', '>', 'SELECT AVG(age) FROM users')
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE age > (SELECT AVG(age) FROM users) ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereInSubquery(): void
    {
        $entities = $this->qb()
            ->whereInSubquery('id', 'SELECT id FROM users WHERE active = 0')
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE id IN (SELECT id FROM users WHERE active = 0) ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'active']);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereNotInSubquery(): void
    {
        $entities = $this->qb()
            ->whereNotInSubquery('id', 'SELECT id FROM users WHERE active = 0')
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE id NOT IN (SELECT id FROM users WHERE active = 0) ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'active']);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_whereExists_subquery(): void
    {
        $entities = $this->qb()
            ->whereExists(function ($sub) {
                $sub->where('active', 0);
            })
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users AS e WHERE EXISTS (SELECT 1 FROM users AS e WHERE e.active = 0) ORDER BY e.id ASC');

        self::assertCount(count($rows), $entities);
    }

    public function test_whereNotExists_subquery(): void
    {
        $entities = $this->qb()
            ->whereNotExists(function ($sub) {
                $sub->where('name', 'NonExistentPerson');
            })
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users AS e WHERE NOT EXISTS (SELECT 1 FROM users AS e WHERE e.name = \'NonExistentPerson\') ORDER BY e.id ASC');

        self::assertCount(count($rows), $entities);
    }

    public function test_selectSub(): void
    {
        $entities = $this->qb()
            ->selectSub('SELECT MAX(age) FROM users', 'max_age')
            ->orderBy('id')
            ->limit(5)
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT *, (SELECT MAX(age) FROM users) AS max_age FROM users ORDER BY id ASC LIMIT 5');
        self::assertCount(count($rows), $entities);
    }

    // ──── Column comparison ────

    public function test_whereColumn_compares_two_columns(): void
    {
        $entities = $this->qb()->whereColumn('id', 'age')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users WHERE id = age');
        self::assertCount(count($rows), $entities);
    }

    // ──── Date methods ────

    public function test_whereDate(): void
    {
        $entities = $this->qb()
            ->whereDate('registered_at', '=', '2023-01-01')
            ->get()->toArray();

        $rows = $this->dbalAll("SELECT * FROM users WHERE DATE(registered_at) = '2023-01-01'");
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_whereYear(): void
    {
        $entities = $this->qb()
            ->whereYear('registered_at', '=', 2023)
            ->get()->toArray();

        $rows = $this->dbalAll("SELECT * FROM users WHERE STRFTIME('%Y', registered_at) = '2023'");
        $this->assertRowsMatch($rows, $entities);
        self::assertGreaterThan(0, count($entities));
    }

    // ──── Combination tests ────

    public function test_complex_where_and_or_combined(): void
    {
        $entities = $this->qb()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('age', '>', 50)->orWhere('score', '>', 80.0);
            })
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 AND (age > 50 OR score > 80.0) ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age', 'active']);
    }

    public function test_multiple_wheres_chained(): void
    {
        $entities = $this->qb()
            ->where('active', 1)
            ->where('age', '>', 30)
            ->where('age', '<', 60)
            ->orderBy('id')
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 AND age > 30 AND age < 60 ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age', 'active']);
    }

    public function test_where_order_limit_offset_combined(): void
    {
        $entities = $this->qb()
            ->where('active', 1)
            ->orderBy('age', 'DESC')
            ->limit(5)
            ->offset(2)
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 ORDER BY age DESC LIMIT 5 OFFSET 2');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'age']);
        self::assertCount(5, $entities);
    }

    public function test_select_where_order_limit_full_query(): void
    {
        $entities = $this->qb()
            ->where('active', 1)
            ->whereNotNull('bio')
            ->orderBy('name', 'ASC')
            ->limit(10)
            ->get()->toArray();

        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 AND bio IS NOT NULL ORDER BY name ASC LIMIT 10');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'bio']);
    }

    // ──── Edge cases ────

    public function test_empty_result_set(): void
    {
        $entities = $this->qb()->where('age', '>', 9999)->get()->toArray();
        self::assertCount(0, $entities);
    }

    public function test_query_with_no_conditions_returns_all(): void
    {
        $entities = $this->qb()->orderBy('id')->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY id ASC');
        self::assertCount(50, $entities);
        $this->assertRowsMatch($rows, $entities);
    }

    public function test_limit_zero_returns_empty(): void
    {
        $entities = $this->qb()->limit(0)->get()->toArray();
        self::assertCount(0, $entities);
    }

    public function test_where_with_special_characters_in_value(): void
    {
        $entities = $this->qb()->where('name', "O'Reilly")->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE name = ?", ["O'Reilly"]);
        self::assertCount(count($rows), $entities);
    }

    public function test_where_with_unicode_value(): void
    {
        $entities = $this->qb()->whereContains('bio', '日本語')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE bio LIKE '%日本語%'");
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'bio']);
        self::assertGreaterThan(0, count($entities));
    }

    public function test_where_with_percent_in_like(): void
    {
        $entities = $this->qb()->whereLike('bio', '%100\%%')->get()->toArray();
        $rows     = $this->dbalAll("SELECT * FROM users WHERE bio LIKE '%100\%%'");
        self::assertCount(count($rows), $entities);
    }

    // ──── Additional aggregate equivalences ────

    public function test_sum_equivalence(): void
    {
        $weaverSum = $this->qb()->sum('age');
        $dbalSum   = $this->dbalScalar('SELECT SUM(age) FROM users');
        self::assertEquals((float) $dbalSum, (float) $weaverSum);
    }

    public function test_avg_equivalence(): void
    {
        $weaverAvg = $this->qb()->avg('age');
        $dbalAvg   = (float) $this->dbalScalar('SELECT AVG(age) FROM users');
        self::assertEqualsWithDelta($dbalAvg, $weaverAvg, 0.01);
    }

    public function test_min_equivalence(): void
    {
        $weaverMin = $this->qb()->min('age');
        $dbalMin   = $this->dbalScalar('SELECT MIN(age) FROM users');
        self::assertEquals($dbalMin, $weaverMin);
    }

    public function test_max_equivalence(): void
    {
        $weaverMax = $this->qb()->max('age');
        $dbalMax   = $this->dbalScalar('SELECT MAX(age) FROM users');
        self::assertEquals($dbalMax, $weaverMax);
    }

    // ──── pluck / value ────

    public function test_pluck_single_column(): void
    {
        $weaverNames = $this->qb()->where('active', 0)->orderBy('id')->pluck('name');
        $dbalNames   = array_column($this->dbalAll('SELECT name FROM users WHERE active = 0 ORDER BY id ASC'), 'name');
        self::assertSame($dbalNames, $weaverNames);
    }

    public function test_pluck_with_key_column(): void
    {
        $weaverMap = $this->qb()->orderBy('id')->limit(5)->pluck('name', 'id');
        $dbalRows  = $this->dbalAll('SELECT name, id FROM users ORDER BY id ASC LIMIT 5');
        $dbalMap   = [];
        foreach ($dbalRows as $r) {
            $dbalMap[$r['id']] = $r['name'];
        }
        self::assertSame($dbalMap, $weaverMap);
    }

    public function test_value_returns_single(): void
    {
        $weaverVal = $this->qb()->where('id', 1)->value('name');
        $dbalVal   = $this->dbalScalar('SELECT name FROM users WHERE id = 1');
        self::assertSame($dbalVal, $weaverVal);
    }

    // ──── fetchRaw ────

    public function test_fetchRaw_returns_arrays(): void
    {
        $weaverRows = $this->qb()->where('active', 0)->orderBy('id')->fetchRaw();
        $dbalRows   = $this->dbalAll('SELECT * FROM users WHERE active = 0 ORDER BY id ASC');
        self::assertCount(count($dbalRows), $weaverRows);
        for ($i = 0; $i < count($dbalRows); $i++) {
            self::assertSame($dbalRows[$i]['name'], $weaverRows[$i]['name']);
            self::assertSame($dbalRows[$i]['email'], $weaverRows[$i]['email']);
        }
    }

    // ──── doesntExist ────

    public function test_doesntExist_true(): void
    {
        self::assertTrue($this->qb()->where('name', 'ZZZNobody')->doesntExist());
    }

    public function test_doesntExist_false(): void
    {
        self::assertFalse($this->qb()->where('name', 'Alice')->doesntExist());
    }

    // ──── cursor / lazy ────

    public function test_cursor_returns_all(): void
    {
        $entities = iterator_to_array($this->qb()->where('active', 0)->orderBy('id')->cursor());
        $rows     = $this->dbalAll('SELECT * FROM users WHERE active = 0 ORDER BY id ASC');
        self::assertCount(count($rows), $entities);
        foreach ($entities as $i => $e) {
            self::assertSame($rows[$i]['name'], $e->name);
        }
    }

    // ──── forPage ────

    public function test_forPage(): void
    {
        $entities = $this->qb()->orderBy('id')->forPage(3, 10)->get()->toArray();
        $rows     = $this->dbalAll('SELECT * FROM users ORDER BY id ASC LIMIT 10 OFFSET 20');
        $this->assertRowsMatch($rows, $entities);
        self::assertSame(21, $entities[0]->id);
    }

    // ──── when / unless ────

    public function test_when_true_applies(): void
    {
        $entities = $this->qb()
            ->when(true, fn ($q) => $q->where('active', 1))
            ->orderBy('id')
            ->get()->toArray();
        $rows = $this->dbalAll('SELECT * FROM users WHERE active = 1 ORDER BY id ASC');
        $this->assertRowsMatch($rows, $entities, ['id', 'name', 'active']);
    }

    public function test_when_false_skips(): void
    {
        $entities = $this->qb()
            ->when(false, fn ($q) => $q->where('active', 1))
            ->orderBy('id')
            ->get()->toArray();
        $rows = $this->dbalAll('SELECT * FROM users ORDER BY id ASC');
        self::assertCount(count($rows), $entities);
        self::assertCount(50, $entities);
    }

    public function test_unless_true_skips(): void
    {
        $entities = $this->qb()
            ->unless(true, fn ($q) => $q->where('active', 0))
            ->orderBy('id')
            ->get()->toArray();
        self::assertCount(50, $entities);
    }

    // ──── groupBy + having ────

    public function test_groupBy_havingRaw(): void
    {
        $raw = $this->qb()
            ->select('active', 'COUNT(*) AS cnt')
            ->groupBy('active')
            ->havingRaw('cnt > 5')
            ->fetchRaw();

        $dbal = $this->dbalAll('SELECT active, COUNT(*) AS cnt FROM users GROUP BY active HAVING cnt > 5');
        self::assertCount(count($dbal), $raw);
        for ($i = 0; $i < count($dbal); $i++) {
            self::assertSame((int) $dbal[$i]['active'], (int) $raw[$i]['active']);
            self::assertSame((int) $dbal[$i]['cnt'], (int) $raw[$i]['cnt']);
        }
    }

    // ──── one() ────

    public function test_one_returns_single(): void
    {
        $entity = $this->qb()->where('id', 1)->one();
        $row    = $this->dbalOne('SELECT * FROM users WHERE id = 1');
        self::assertNotFalse($row);
        self::assertSame((int) $row['id'], $entity->id);
        self::assertSame($row['name'], $entity->name);
    }

    public function test_one_throws_on_empty(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->qb()->where('id', 99999)->one();
    }

    // ──── update / delete via query builder ────

    public function test_update_via_querybuilder(): void
    {
        $affected = $this->qb()->where('id', 1)->update(['name' => 'AliceUpdated']);
        $this->dConn->executeStatement("UPDATE users SET name = 'AliceUpdated' WHERE id = 1");

        $wRow = $this->wConn->fetchAssociative('SELECT name FROM users WHERE id = 1');
        $dRow = $this->dConn->fetchAssociative('SELECT name FROM users WHERE id = 1');

        self::assertSame(1, $affected);
        self::assertSame($dRow['name'], $wRow['name']);
        self::assertSame('AliceUpdated', $wRow['name']);
    }

    public function test_delete_via_querybuilder(): void
    {
        $affected = $this->qb()->where('id', 50)->delete();
        $this->dConn->executeStatement('DELETE FROM users WHERE id = 50');

        $wCount = (int) $this->wConn->fetchOne('SELECT COUNT(*) FROM users');
        $dCount = (int) $this->dConn->fetchOne('SELECT COUNT(*) FROM users');

        self::assertSame(1, $affected);
        self::assertSame($dCount, $wCount);
        self::assertSame(49, $wCount);
    }

    // ──── toSQL ────

    public function test_toSQL_produces_valid_sql(): void
    {
        $sql = $this->qb()->where('active', 1)->orderBy('name')->toSQL();
        self::assertStringContainsString('WHERE', $sql);
        self::assertStringContainsString('ORDER BY', $sql);
    }

    // ──── clone independence ────

    public function test_clone_independence(): void
    {
        $base = $this->qb()->where('active', 1);
        $clone = clone $base;
        $clone->where('age', '>', 50);

        $baseCount  = $base->count();
        $cloneCount = $clone->count();

        $dbalBase  = (int) $this->dbalScalar('SELECT COUNT(*) FROM users WHERE active = 1');
        $dbalClone = (int) $this->dbalScalar('SELECT COUNT(*) FROM users WHERE active = 1 AND age > 50');

        self::assertSame($dbalBase, $baseCount);
        self::assertSame($dbalClone, $cloneCount);
        self::assertGreaterThan($cloneCount, $baseCount);
    }

    // ──── chunk ────

    public function test_chunk_processes_all_rows(): void
    {
        $collected = [];
        $this->qb()->orderBy('id')->chunk(10, function ($batch) use (&$collected) {
            foreach ($batch as $entity) {
                $collected[] = $entity->id;
            }
        });

        self::assertCount(50, $collected);
        self::assertSame(1, $collected[0]);
        self::assertSame(50, $collected[49]);
    }
}
