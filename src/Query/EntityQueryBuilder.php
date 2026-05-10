<?php

declare(strict_types=1);

namespace Weaver\ORM\Query;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ParameterType;
use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\Exception\EntityNotFoundException;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Pagination\Page;
use Weaver\ORM\Pagination\SimplePage;
use Weaver\ORM\Query\Filter\QueryFilterRegistry;
use Weaver\ORM\Cache\QueryResultCache;
use Weaver\ORM\Relation\RelationLoader;

final class EntityQueryBuilder
{

    private \Weaver\ORM\DBAL\QueryBuilder $qb;

    private array $eagerLoads = [];

    private array $withCounts = [];

    private ?int $maxRows = null;

    private ?string $lockMode = null;

    private ?string $comment = null;

    private bool $withTrashed = false;

    private bool $onlyTrashed = false;

    private array $ctes = [];

    private bool $recursiveCte = false;

    private int $paramCount = 0;

    private array $unions = [];

    private QueryFilterRegistry $filterRegistry;

    private bool $filtersApplied = false;

    private bool $softDeleteApplied = false;

    private static array $macros = [];

    private ?QueryResultCache $queryResultCache = null;

    private ?int $cacheTtl = null;

    private ?string $cacheKey = null;

    private bool $invalidateOnExecute = false;

    public static function macro(string $name, \Closure $callback): void
    {
        static::$macros[$name] = $callback;
    }

    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    public static function flushMacros(): void
    {
        static::$macros = [];
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (!isset(static::$macros[$name])) {
            throw new \BadMethodCallException(
                sprintf('Call to undefined method %s::%s()', static::class, $name)
            );
        }

        $macro = static::$macros[$name];

        return $macro->bindTo($this, static::class)(...$arguments);
    }

    private array $implicitJoins = [];

    /**
     * If non-null, every entity hydrated by first()/get() is registered
     * with this UnitOfWork so subsequent property mutations are tracked.
     * EntityRepository::query() wires this on; raw EntityQueryBuilder
     * users pay no observability tax.
     */
    private ?\Weaver\ORM\Persistence\UnitOfWork $trackingUoW = null;

    private bool $trackingEnabled = true;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $entityClass,
        private readonly AbstractEntityMapper $mapper,
        private readonly ?EntityHydrator $hydrator = null,
        private readonly ?RelationLoader $relationLoader = null,
        private string $alias = 'e',
        ?QueryFilterRegistry $filterRegistry = null,
    ) {
        $this->qb             = new \Weaver\ORM\DBAL\QueryBuilder();
        $this->filterRegistry = $filterRegistry ?? new QueryFilterRegistry();

        $this->qb->from($mapper->getTableName(), $this->alias);
        $this->qb->select($this->alias . '.*');
    }

    /**
     * Wire the UnitOfWork that should receive hydrated entities.
     * EntityRepository::query() calls this; external callers normally
     * don't need to.
     */
    public function setUnitOfWork(\Weaver\ORM\Persistence\UnitOfWork $uow): static
    {
        $this->trackingUoW = $uow;
        return $this;
    }

    /**
     * Opt out of UoW tracking for this query (read-only / projection use).
     */
    public function withoutTracking(): static
    {
        $this->trackingEnabled = false;
        return $this;
    }

    private function trackHydratedEntities(EntityCollection $collection): void
    {
        if (!$this->trackingEnabled || $this->trackingUoW === null || $collection->isEmpty()) {
            return;
        }
        foreach ($collection as $entity) {
            if (is_object($entity)) {
                $this->trackingUoW->track($entity, $this->entityClass);
            }
        }
    }

    public function __clone(): void
    {
        $this->qb = clone $this->qb;
    }

    public function select(string ...$columns): static
    {
        $this->qb->select(...$columns);

        return $this;
    }

    public function addSelect(string ...$columns): static
    {
        $this->qb->addSelect(...$columns);

        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->qb->addSelect($expression);

        foreach ($bindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function selectSub(string|\Closure $query, string $alias, array $bindings = []): static
    {
        [$subSql, $subBindings] = $this->resolveSubquery($query, $alias, $bindings);

        $this->qb->select($this->alias . '.*', '(' . $subSql . ') AS ' . $alias);

        foreach ($subBindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function addSelectSub(string|\Closure $query, string $alias, array $bindings = []): static
    {
        [$subSql, $subBindings] = $this->resolveSubquery($query, $alias, $bindings);

        $this->qb->addSelect('(' . $subSql . ') AS ' . $alias);

        foreach ($subBindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function whereSubquery(string $column, string $operator, string|\Closure $subquery, array $bindings = []): static
    {
        [$subSql, $subBindings] = $this->resolveSubquery($subquery, $column . '_wsub', $bindings);

        $this->qb->andWhere(sprintf('%s %s (%s)', $column, $operator, $subSql));

        foreach ($subBindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function whereInSubquery(string $column, string|\Closure $subquery, array $bindings = []): static
    {
        return $this->whereSubquery($column, 'IN', $subquery, $bindings);
    }

    public function whereNotInSubquery(string $column, string|\Closure $subquery, array $bindings = []): static
    {
        return $this->whereSubquery($column, 'NOT IN', $subquery, $bindings);
    }

    public function distinct(): static
    {
        $this->qb->distinct();

        return $this;
    }

    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            $sub = $this->newSubBuilder();
            $column($sub);
            $subSql = $sub->qb->getSQL();

            if (preg_match('/\bWHERE\b(.+)$/si', $subSql, $m)) {
                $this->qb->andWhere('(' . trim($m[1]) . ')');
                $this->mergeParameters($sub);
            }

            return $this;
        }

        if (str_contains($column, '.') && !str_contains($column, ' ')) {
            [$relationName, $relColumn] = explode('.', $column, 2);
            $column = $this->resolveImplicitJoin($relationName) . '.' . $relColumn;
        }

        $column = $this->resolveColumnName($column);
        $expr = $this->buildWhereClause($column, ...$this->normalizeOperatorValue($operatorOrValue, $value));
        $this->qb->andWhere($expr);

        return $this;
    }

    public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            $sub = $this->newSubBuilder();
            $column($sub);
            $subSql = $sub->qb->getSQL();
            if (preg_match('/\bWHERE\b(.+)$/si', $subSql, $m)) {
                $this->qb->orWhere('(' . trim($m[1]) . ')');
                $this->mergeParameters($sub);
            }

            return $this;
        }

        if (str_contains($column, '.') && !str_contains($column, ' ')) {
            [$relationName, $relColumn] = explode('.', $column, 2);
            $column = $this->resolveImplicitJoin($relationName) . '.' . $relColumn;
        }

        $column = $this->resolveColumnName($column);
        $expr = $this->buildWhereClause($column, ...$this->normalizeOperatorValue($operatorOrValue, $value));
        $this->qb->orWhere($expr);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->qb->andWhere($column . ' IS NULL');

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->qb->andWhere($column . ' IS NOT NULL');

        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        if (str_contains($column, '.') && !str_contains($column, ' ')) {
            [$relationName, $relColumn] = explode('.', $column, 2);
            $column = $this->resolveImplicitJoin($relationName) . '.' . $relColumn;
        }

        if ($values === []) {
            $this->qb->andWhere('1=0');

            return $this;
        }

        $placeholders = [];

        foreach ($values as $v) {
            $param              = $this->buildParameterName($column);
            $placeholders[]     = ':' . $param;
            $this->qb->setParameter($param, $v);
        }

        $this->qb->andWhere($column . ' IN (' . implode(', ', $placeholders) . ')');

        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        if ($values === []) {
            return $this;
        }

        $placeholders = [];

        foreach ($values as $v) {
            $param          = $this->buildParameterName($column);
            $placeholders[] = ':' . $param;
            $this->qb->setParameter($param, $v);
        }

        $this->qb->andWhere($column . ' NOT IN (' . implode(', ', $placeholders) . ')');

        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $pMin = $this->buildParameterName($column . '_min');
        $pMax = $this->buildParameterName($column . '_max');

        $this->qb->setParameter($pMin, $min);
        $this->qb->setParameter($pMax, $max);
        $this->qb->andWhere(sprintf('%s BETWEEN :%s AND :%s', $column, $pMin, $pMax));

        return $this;
    }

    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $pMin = $this->buildParameterName($column . '_min');
        $pMax = $this->buildParameterName($column . '_max');

        $this->qb->setParameter($pMin, $min);
        $this->qb->setParameter($pMax, $max);
        $this->qb->andWhere(sprintf('%s NOT BETWEEN :%s AND :%s', $column, $pMin, $pMax));

        return $this;
    }

    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->qb->andWhere($expression);

        foreach ($bindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function whereDate(string $column, string $operator, string|\DateTimeInterface $value): static
    {
        $this->assertDateOperator($operator);

        $dateStr = $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : $value;

        $param    = $this->buildParameterName($column . '_date');
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Weaver\ORM\DBAL\Platform\SqlitePlatform;

        $expr = $isSqlite
            ? sprintf('DATE(%s) %s :%s', $column, $operator, $param)
            : sprintf('%s::date %s :%s', $column, $operator, $param);

        return $this->whereRaw($expr, [$param => $dateStr]);
    }

    public function whereYear(string $column, string $operator, int $year): static
    {
        $this->assertDateOperator($operator);

        $param    = $this->buildParameterName($column . '_year');
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Weaver\ORM\DBAL\Platform\SqlitePlatform;

        if ($isSqlite) {
            $expr  = sprintf("STRFTIME('%%Y', %s) %s :%s", $column, $operator, $param);
            $bound = str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        } else {
            $expr  = sprintf('EXTRACT(YEAR FROM %s) %s :%s', $column, $operator, $param);
            $bound = $year;
        }

        return $this->whereRaw($expr, [$param => $bound]);
    }

    public function whereMonth(string $column, string $operator, int $month): static
    {
        $this->assertDateOperator($operator);

        $param    = $this->buildParameterName($column . '_month');
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Weaver\ORM\DBAL\Platform\SqlitePlatform;

        if ($isSqlite) {
            $expr  = sprintf("STRFTIME('%%m', %s) %s :%s", $column, $operator, $param);
            $bound = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        } else {
            $expr  = sprintf('EXTRACT(MONTH FROM %s) %s :%s', $column, $operator, $param);
            $bound = $month;
        }

        return $this->whereRaw($expr, [$param => $bound]);
    }

    public function whereDay(string $column, string $operator, int $day): static
    {
        $this->assertDateOperator($operator);

        $param    = $this->buildParameterName($column . '_day');
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Weaver\ORM\DBAL\Platform\SqlitePlatform;

        if ($isSqlite) {
            $expr  = sprintf("STRFTIME('%%d', %s) %s :%s", $column, $operator, $param);
            $bound = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        } else {
            $expr  = sprintf('EXTRACT(DAY FROM %s) %s :%s', $column, $operator, $param);
            $bound = $day;
        }

        return $this->whereRaw($expr, [$param => $bound]);
    }

    public function whereTime(string $column, string $operator, string $time): static
    {
        $this->assertDateOperator($operator);

        $param    = $this->buildParameterName($column . '_time');
        $platform = $this->connection->getDatabasePlatform();
        $isSqlite = $platform instanceof \Weaver\ORM\DBAL\Platform\SqlitePlatform;

        $expr = $isSqlite
            ? sprintf('TIME(%s) %s :%s', $column, $operator, $param)
            : sprintf('%s::time %s :%s', $column, $operator, $param);

        return $this->whereRaw($expr, [$param => $time]);
    }

    private function assertDateOperator(string $operator): void
    {
        $valid = ['=', '!=', '<>', '<', '>', '<=', '>='];

        if (!in_array($operator, $valid, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid date operator "%s". Supported operators: %s.',
                    $operator,
                    implode(', ', $valid),
                )
            );
        }
    }

    public function orWhereRaw(string $expression, array $bindings = []): static
    {
        $this->qb->orWhere($expression);

        foreach ($bindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function whereExists(\Closure $subquery): static
    {
        $sub = $this->newSubBuilder();
        $subquery($sub);
        $this->qb->andWhere('EXISTS (' . $sub->toSQL() . ')');
        $this->mergeParameters($sub);

        return $this;
    }

    public function whereNotExists(\Closure $subquery): static
    {
        $sub = $this->newSubBuilder();
        $subquery($sub);
        $this->qb->andWhere('NOT EXISTS (' . $sub->toSQL() . ')');
        $this->mergeParameters($sub);

        return $this;
    }

    public function whereColumn(string $col1, string $col2): static
    {
        $this->qb->andWhere(sprintf('%s = %s', $col1, $col2));

        return $this;
    }

    public function whereJsonContains(string $column, mixed $value): static
    {
        $param     = $this->buildParameterName($column);
        $jsonValue = is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR);

        $this->qb->setParameter($param, $jsonValue);

        $driverName = $this->connection->getDriver()::class;

        if (str_contains(strtolower($driverName), 'pgsql') || str_contains(strtolower($driverName), 'postgresql')) {
            $this->qb->andWhere(sprintf('%s @> :%s', $column, $param));
        } else {
            $this->qb->andWhere(sprintf('JSON_CONTAINS(%s, :%s)', $column, $param));
        }

        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        $param = $this->buildParameterName($column);
        $this->qb->setParameter($param, $pattern);
        $this->qb->andWhere(sprintf('%s LIKE :%s', $column, $param));

        return $this;
    }

    public function whereNotLike(string $column, string $pattern): static
    {
        $param = $this->buildParameterName($column);
        $this->qb->setParameter($param, $pattern);
        $this->qb->andWhere(sprintf('%s NOT LIKE :%s', $column, $param));

        return $this;
    }

    public function whereILike(string $column, string $pattern): static
    {
        $param = $this->buildParameterName($column);
        $this->qb->setParameter($param, mb_strtolower($pattern));
        $this->qb->andWhere(sprintf('LOWER(%s) LIKE :%s', $column, $param));

        return $this;
    }

    public function whereStartsWith(string $column, string $value): static
    {
        return $this->whereLike($column, $value . '%');
    }

    public function whereEndsWith(string $column, string $value): static
    {
        return $this->whereLike($column, '%' . $value);
    }

    public function whereContains(string $column, string $value): static
    {
        return $this->whereLike($column, '%' . $value . '%');
    }

    public function whereFullText(
        string|array $columns,
        string $query,
        array $options = [],
    ): static {
        $columns  = (array) $columns;
        $language = $options['language'] ?? 'english';
        $mode     = $options['mode']     ?? 'boolean';

        $platform   = $this->connection->getDatabasePlatform();
        $isPostgres = $platform instanceof \Weaver\ORM\DBAL\Platform\PostgresPlatform;
        $isMySql    = $platform instanceof \Weaver\ORM\DBAL\Platform\MysqlPlatform;

        if ($isPostgres) {

            $parts = [];
            foreach ($columns as $column) {
                $vecParam   = $this->buildParameterName('fts_lang_vec');
                $queryParam = $this->buildParameterName('fts_lang_q');
                $this->qb->setParameter($vecParam, $language);
                $this->qb->setParameter($queryParam, $query);
                $parts[] = sprintf(
                    "to_tsvector(:%s, %s) @@ plainto_tsquery(:%s, :%s)",
                    $vecParam,
                    $column,
                    $vecParam,
                    $queryParam,
                );
            }
            $this->qb->andWhere('(' . implode(' OR ', $parts) . ')');

            return $this;
        }

        if ($isMySql) {
            $colList  = implode(', ', $columns);
            $param    = $this->buildParameterName('fts_q');
            $this->qb->setParameter($param, $query);
            $modeStr = ($mode === 'natural') ? 'IN NATURAL LANGUAGE MODE' : 'IN BOOLEAN MODE';
            $this->qb->andWhere(sprintf('MATCH(%s) AGAINST(:%s %s)', $colList, $param, $modeStr));

            return $this;
        }

        $words = array_filter(array_map('trim', explode(' ', $query)));
        $parts = [];
        foreach ($columns as $column) {
            foreach ($words as $word) {
                $param = $this->buildParameterName('fts_like');
                $this->qb->setParameter($param, '%' . $word . '%');
                $parts[] = sprintf('%s LIKE :%s', $column, $param);
            }
        }

        if ($parts !== []) {
            $this->qb->andWhere('(' . implode(' OR ', $parts) . ')');
        }

        return $this;
    }

    public function whereFullTextBoolean(string|array $columns, string $query): static
    {
        return $this->whereFullText($columns, $query, ['mode' => 'boolean']);
    }

    public function orderByRelevance(string|array $columns, string $query): static
    {
        $columns  = (array) $columns;
        $platform = $this->connection->getDatabasePlatform();

        $isPostgres = $platform instanceof \Weaver\ORM\DBAL\Platform\PostgresPlatform;
        $isMySql    = $platform instanceof \Weaver\ORM\DBAL\Platform\MysqlPlatform;

        if ($isPostgres) {

            $column     = $columns[0];
            $langParam  = $this->buildParameterName('rank_lang');
            $queryParam = $this->buildParameterName('rank_q');
            $this->qb->setParameter($langParam, 'english');
            $this->qb->setParameter($queryParam, $query);
            $expr = sprintf(
                'ts_rank(to_tsvector(:%s, %s), plainto_tsquery(:%s, :%s)) DESC',
                $langParam,
                $column,
                $langParam,
                $queryParam,
            );
            $this->qb->addOrderBy($expr);

            return $this;
        }

        if ($isMySql) {
            $colList = implode(', ', $columns);
            $param   = $this->buildParameterName('rank_q');
            $this->qb->setParameter($param, $query);
            $this->qb->addOrderBy(sprintf('MATCH(%s) AGAINST(:%s) DESC', $colList, $param));

            return $this;
        }

        return $this;
    }

    public function join(string $table, string $alias, string $condition): static
    {
        $this->qb->join($this->alias, $table, $alias, $condition);

        return $this;
    }

    public function leftJoin(string $table, string $alias, string $condition): static
    {
        $this->qb->leftJoin($this->alias, $table, $alias, $condition);

        return $this;
    }

    public function rightJoin(string $table, string $alias, string $condition): static
    {
        $this->qb->rightJoin($this->alias, $table, $alias, $condition);

        return $this;
    }

    public function crossJoin(string $table, string $alias): static
    {
        $this->qb->join($this->alias, $table, $alias, '1=1');

        return $this;
    }

    public function joinSub(\Closure $subquery, string $alias, string $condition): static
    {
        $sub = $this->newSubBuilder();
        $subquery($sub);
        $this->qb->join($this->alias, '(' . $sub->toSQL() . ')', $alias, $condition);
        $this->mergeParameters($sub);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        if (str_contains($column, '.') && !str_contains($column, ' ')) {
            [$relationName, $relColumn] = explode('.', $column, 2);
            $column = $this->resolveImplicitJoin($relationName) . '.' . $relColumn;
        }

        $column = $this->resolveColumnName($column);
        $this->qb->orderBy($column, strtoupper($direction));

        return $this;
    }

    public function addOrderBy(string $column, string $direction = 'ASC'): static
    {
        if (str_contains($column, '.') && !str_contains($column, ' ')) {
            [$relationName, $relColumn] = explode('.', $column, 2);
            $column = $this->resolveImplicitJoin($relationName) . '.' . $relColumn;
        }

        $this->qb->addOrderBy($column, strtoupper($direction));

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function orderByRaw(string $expression): static
    {
        $this->qb->addOrderByRaw($expression);

        return $this;
    }

    public function inRandomOrder(): static
    {
        $driverName = strtolower($this->connection->getDriver()::class);
        $fn         = (str_contains($driverName, 'pgsql') || str_contains($driverName, 'sqlite'))
            ? 'RANDOM()'
            : 'RAND()';

        $this->qb->addOrderByRaw($fn);

        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $this->qb->groupBy(...$columns);

        return $this;
    }

    public function groupByRaw(string $expression): static
    {
        $this->qb->groupBy($expression);

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): static
    {
        $param = $this->buildParameterName($column);
        $this->qb->setParameter($param, $value);
        $this->qb->andHaving(sprintf('%s %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->qb->andHaving($expression);

        foreach ($bindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function orHavingRaw(string $expression, array $bindings = []): static
    {
        $this->qb->orHaving($expression);

        foreach ($bindings as $key => $value) {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function havingCount(string $column, string $operator, int $value): static
    {
        $this->assertValidHavingOperator($operator);
        $param = $this->buildParameterName('hav_count_' . $column);
        $this->qb->setParameter($param, $value, ParameterType::INTEGER);
        $this->qb->andHaving(sprintf('COUNT(%s) %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingSum(string $column, string $operator, int|float $value): static
    {
        $this->assertValidHavingOperator($operator);
        $param = $this->buildParameterName('hav_sum_' . $column);
        $type  = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        $this->qb->setParameter($param, $value, $type);
        $this->qb->andHaving(sprintf('SUM(%s) %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingAvg(string $column, string $operator, int|float $value): static
    {
        $this->assertValidHavingOperator($operator);
        $param = $this->buildParameterName('hav_avg_' . $column);
        $type  = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        $this->qb->setParameter($param, $value, $type);
        $this->qb->andHaving(sprintf('AVG(%s) %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingMin(string $column, string $operator, int|float $value): static
    {
        $this->assertValidHavingOperator($operator);
        $param = $this->buildParameterName('hav_min_' . $column);
        $type  = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        $this->qb->setParameter($param, $value, $type);
        $this->qb->andHaving(sprintf('MIN(%s) %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingMax(string $column, string $operator, int|float $value): static
    {
        $this->assertValidHavingOperator($operator);
        $param = $this->buildParameterName('hav_max_' . $column);
        $type  = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        $this->qb->setParameter($param, $value, $type);
        $this->qb->andHaving(sprintf('MAX(%s) %s :%s', $column, $operator, $param));

        return $this;
    }

    public function havingBetween(string $column, mixed $min, mixed $max): static
    {
        $pMin = $this->buildParameterName('hav_' . $column . '_min');
        $pMax = $this->buildParameterName('hav_' . $column . '_max');
        $typeMin = is_int($min) ? ParameterType::INTEGER : ParameterType::STRING;
        $typeMax = is_int($max) ? ParameterType::INTEGER : ParameterType::STRING;
        $this->qb->setParameter($pMin, $min, $typeMin);
        $this->qb->setParameter($pMax, $max, $typeMax);
        $this->qb->andHaving(sprintf('%s BETWEEN :%s AND :%s', $column, $pMin, $pMax));

        return $this;
    }

    private function assertValidHavingOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>='];

        if (!in_array($operator, $allowed, strict: true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid HAVING operator "%s". Allowed: %s',
                    $operator,
                    implode(', ', $allowed),
                )
            );
        }
    }

    public function limit(int $limit): static
    {
        $this->qb->setMaxResults($limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->qb->setFirstResult($offset);

        return $this;
    }

    public function forPage(int $page, int $perPage): static
    {
        return $this
            ->limit($perPage)
            ->offset(max(0, ($page - 1) * $perPage));
    }

    public function lock(string $mode): static
    {
        $this->lockMode = $mode;

        return $this;
    }

    public function matching(Criteria\Criteria $criteria): static
    {
        return (new Criteria\CriteriaApplier())->apply($criteria, $this);
    }

    public function lockForUpdate(): static
    {
        return $this->lock('FOR UPDATE');
    }

    public function sharedLock(): static
    {
        return $this->lock('LOCK IN SHARE MODE');
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;

        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->onlyTrashed = true;
        $this->withTrashed = false;

        return $this;
    }

    public function withoutTrashed(): static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;

        return $this;
    }

    public function applyFilters(): static
    {
        if ($this->filtersApplied) {
            return $this;
        }

        $this->filtersApplied = true;

        foreach ($this->filterRegistry->getFiltersFor($this->entityClass) as $filter) {
            $filter->apply($this);
        }

        return $this;
    }

    public function union(string|\Closure $query, array $bindings = []): static
    {
        [$sql, $bindings] = $this->resolveUnionQuery($query, $bindings);
        $this->unions[] = ['sql' => $sql, 'bindings' => $bindings, 'all' => false];

        return $this;
    }

    public function unionAll(string|\Closure $query, array $bindings = []): static
    {
        [$sql, $bindings] = $this->resolveUnionQuery($query, $bindings);
        $this->unions[] = ['sql' => $sql, 'bindings' => $bindings, 'all' => true];

        return $this;
    }

    public function setParameter(string $key, mixed $value, \Weaver\ORM\DBAL\ParameterType|string|null $type = null): static
    {
        if ($type !== null) {
            $this->qb->setParameter($key, $value, $type);
        } else {
            $this->qb->setParameter($key, $value);
        }

        return $this;
    }

    public function maxRows(int $n): static
    {
        $this->maxRows = $n;

        return $this;
    }

    public function comment(string $text): static
    {
        $this->comment = $text;

        return $this;
    }

    public function tap(\Closure $fn): static
    {
        $fn($this);

        return $this;
    }

    public function when(bool|callable $condition, \Closure $callback, ?\Closure $default = null): static
    {
        $result = is_callable($condition) ? $condition($this) : $condition;

        if ($result) {
            $callback($this);
        } elseif ($default instanceof \Closure) {
            $default($this);
        }

        return $this;
    }

    public function unless(bool|callable $condition, \Closure $callback, ?\Closure $default = null): static
    {
        return $this->when(
            is_callable($condition) ? fn (): bool => !$condition($this) : !$condition,
            $callback,
            $default,
        );
    }

    public function with(string|array ...$relations): static
    {
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                foreach ($relation as $r) {
                    $this->eagerLoads[] = $r;
                }
            } else {
                $this->eagerLoads[] = $relation;
            }
        }

        return $this;
    }

    public function withCte(string $name, string|\Closure $query, array $bindings = []): static
    {
        if ($query instanceof \Closure) {
            $dbalQb = new \Weaver\ORM\DBAL\QueryBuilder();
            $query($dbalQb);
            $cteSql      = $dbalQb->getSQL();
            $cteBindings = [];

            foreach ($dbalQb->getParameters() as $key => $value) {
                $cteBindings[$key] = $value;
            }
        } else {
            $cteSql      = $query;
            $cteBindings = $bindings;
        }

        $this->ctes[$name] = ['sql' => $cteSql, 'bindings' => $cteBindings];

        return $this;
    }

    public function withRecursiveCte(string $name, string $anchorSql, string $recursiveSql, array $bindings = []): static
    {
        $this->recursiveCte      = true;
        $this->ctes[$name] = [
            'sql'      => $anchorSql . ' UNION ALL ' . $recursiveSql,
            'bindings' => $bindings,
        ];

        return $this;
    }

    public function fromCte(string $cteName): static
    {
        $ref = new \ReflectionProperty($this->qb, 'from');
        $ref->setAccessible(true);
        $ref->setValue($this->qb, null);

        try {
            $refAlias = new \ReflectionProperty($this->qb, 'fromAlias');
            $refAlias->setAccessible(true);
            $refAlias->setValue($this->qb, null);
        } catch (\ReflectionException) {
        }

        $this->qb->from($cteName, $this->alias);

        return $this;
    }

    public function withCount(
        string $countAlias,
        string $relatedTable,
        string $foreignKey,
        string $localKey = 'id',
    ): static {
        $this->withCounts[] = [
            'alias'        => $countAlias,
            'relatedTable' => $relatedTable,
            'foreignKey'   => $foreignKey,
            'localKey'     => $localKey,
        ];

        $subquery = sprintf(
            '(SELECT COUNT(*) FROM %s WHERE %s.%s = %s.%s) AS %s',
            $relatedTable,
            $relatedTable,
            $foreignKey,
            $this->alias,
            $localKey,
            $countAlias,
        );

        $this->qb->addSelect($subquery);

        return $this;
    }

    public function setQueryResultCache(QueryResultCache $cache): static
    {
        $this->queryResultCache = $cache;

        return $this;
    }

    public function cache(int $ttl = 60, ?string $cacheKey = null): static
    {
        $this->cacheTtl = $ttl;
        $this->cacheKey = $cacheKey;

        return $this;
    }

    public function invalidateCache(): static
    {
        $this->invalidateOnExecute = true;

        return $this;
    }

    public function toSQL(): string
    {
        $clone = clone $this;
        $clone->applyAllFilters();

        return $clone->buildSQL();
    }

    public function dump(): static
    {
        var_dump($this->toSQL());

        return $this;
    }

    public function dd(): never
    {
        var_dump($this->toSQL());
        exit(1);
    }

    public function get(array $with = []): EntityCollection
    {
        $this->applyAllFilters();
        $this->applyMaxRows();

        $rows = null;

        if ($this->queryResultCache !== null && $this->cacheTtl !== null) {
            $resolvedKey = $this->resolveQueryCacheKey();

            if ($this->invalidateOnExecute) {
                $this->queryResultCache->invalidate($resolvedKey);
            } else {
                $rows = $this->queryResultCache->get($resolvedKey);
            }

            if ($rows === null) {
                $rows = $this->executeQuery()->fetchAllAssociative();
                $this->queryResultCache->put($resolvedKey, $rows, $this->cacheTtl);
            }
        } else {
            $rows = $this->executeQuery()->fetchAllAssociative();
        }

        $collection = $this->hydrateCollection($rows);

        $this->eagerLoadRelations($collection, array_merge($this->eagerLoads, $with));
        $this->applyWithCounts($collection, $rows);
        $this->trackHydratedEntities($collection);

        return $collection;
    }

    public function first(array $with = []): ?object
    {
        $this->limit(1);
        $this->applyAllFilters();

        $rows = null;

        if ($this->queryResultCache !== null && $this->cacheTtl !== null) {
            $resolvedKey = $this->resolveQueryCacheKey();

            if ($this->invalidateOnExecute) {
                $this->queryResultCache->invalidate($resolvedKey);
            } else {
                $rows = $this->queryResultCache->get($resolvedKey);
            }

            if ($rows === null) {
                $row = $this->executeQuery()->fetchAssociative();
                $rows = $row === false ? [] : [$row];
                $this->queryResultCache->put($resolvedKey, $rows, $this->cacheTtl);
            }
        } else {
            $row = $this->executeQuery()->fetchAssociative();
            $rows = $row === false ? [] : [$row];
        }

        if ($rows === []) {
            return null;
        }

        $collection = $this->hydrateCollection($rows);
        $this->eagerLoadRelations($collection, array_merge($this->eagerLoads, $with));
        $this->trackHydratedEntities($collection);

        $result = $collection->first();

        return $result instanceof \stdClass || is_object($result) ? $result : null;
    }

    public function findByCompositeKey(\Weaver\ORM\Mapping\CompositeKey $key): ?object
    {
        $clone = clone $this;

        foreach ($key->toArray() as $column => $value) {
            $clone->where($column, '=', $value);
        }

        return $clone->first();
    }

    public function firstOrFail(array $with = []): object
    {
        $entity = $this->first($with);

        if ($entity === null) {
            throw EntityNotFoundException::noResults($this->entityClass);
        }

        return $entity;
    }

    public function one(array $with = []): object
    {
        $this->applyAllFilters();
        $this->applyMaxRows();

        $rows = $this->executeQuery()->fetchAllAssociative();

        if ($rows === []) {
            throw EntityNotFoundException::noResults($this->entityClass);
        }

        if (count($rows) > 1) {
            throw EntityNotFoundException::multipleResults($this->entityClass, count($rows));
        }

        $collection = $this->hydrateCollection($rows);
        $this->eagerLoadRelations($collection, array_merge($this->eagerLoads, $with));

        $result = $collection->first();

        if ($result === null) {
            throw EntityNotFoundException::noResults($this->entityClass);
        }

        assert(is_object($result));

        return $result;
    }

    public function fetchRaw(): array
    {
        $this->applyAllFilters();
        $this->applyMaxRows();

        return $this->executeQuery()->fetchAllAssociative();
    }

    public function pluck(string $column, ?string $keyColumn = null): array
    {
        $selectCols = $keyColumn !== null ? [$column, $keyColumn] : [$column];
        $this->qb->select(...$selectCols);

        $this->applyAllFilters();

        $rows   = $this->executeQuery()->fetchAllAssociative();
        $result = [];

        foreach ($rows as $row) {
            if ($keyColumn !== null) {
                $result[$row[$keyColumn]] = $row[$column];
            } else {
                $result[] = $row[$column];
            }
        }

        return $result;
    }

    public function value(string $column): mixed
    {
        $this->qb->select($column)->setMaxResults(1);
        $this->applyAllFilters();

        $row = $this->executeQuery()->fetchAssociative();

        return $row !== false ? $row[$column] : null;
    }

    public function count(?string $column = null): int
    {
        $expr = $column !== null ? 'COUNT(' . $column . ')' : 'COUNT(*)';
        $this->applyAllFilters();

        $clone = clone $this;
        $clone->qb->select($expr);
        $clone->qb->setMaxResults(null);
        $clone->qb->setFirstResult(0);

        if ($this->queryResultCache !== null && $this->cacheTtl !== null) {
            $resolvedKey = $this->resolveQueryCacheKey() . ':count';

            if ($this->invalidateOnExecute) {
                $this->queryResultCache->invalidate($resolvedKey);
            } else {
                $cached = $this->queryResultCache->get($resolvedKey);
                if ($cached !== null && isset($cached[0])) {
                    return (int) $cached[0];
                }
            }

            $raw = $clone->executeQuery()->fetchOne();
            $result = is_numeric($raw) ? (int) $raw : 0;
            $this->queryResultCache->put($resolvedKey, [$result], $this->cacheTtl);

            return $result;
        }

        $raw = $clone->executeQuery()->fetchOne();

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public function sum(string $column): int|float
    {
        $this->applyAllFilters();

        $clone = clone $this;
        $clone->qb->select('SUM(' . $column . ')');

        $result = $clone->executeQuery()->fetchOne();

        if (!is_numeric($result)) {
            return 0;
        }

        return is_float($result + 0) ? (float) $result : (int) $result;
    }

    public function avg(string $column): float
    {
        $this->applyAllFilters();

        $clone = clone $this;
        $clone->qb->select('AVG(' . $column . ')');

        $raw = $clone->executeQuery()->fetchOne();

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    public function min(string $column): mixed
    {
        $this->applyAllFilters();

        $clone = clone $this;
        $clone->qb->select('MIN(' . $column . ')');

        $result = $clone->executeQuery()->fetchOne();

        return $result !== false ? $result : null;
    }

    public function max(string $column): mixed
    {
        $this->applyAllFilters();

        $clone = clone $this;
        $clone->qb->select('MAX(' . $column . ')');

        $result = $clone->executeQuery()->fetchOne();

        return $result !== false ? $result : null;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function cursor(): \Generator
    {
        $this->applyAllFilters();
        $this->applyMaxRows();

        foreach ($this->executeQuery()->fetchAllAssociative() as $row) {
            yield $this->hydrateOne($row);
        }
    }

    public function lazy(): \Generator
    {
        return $this->cursor();
    }

    public function stream(): \Generator
    {
        return $this->cursor();
    }

    public function streamBatched(int $batchSize = 500): \Generator
    {
        $offset = 0;

        do {
            $clone = clone $this;
            $clone->qb->setMaxResults($batchSize);
            $clone->qb->setFirstResult($offset);
            $clone->applyAllFilters();

            $rows = $clone->executeQuery()->fetchAllAssociative();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                yield $this->hydrateOne($row);
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);
    }

    public function chunk(int $size, \Closure $callback): void
    {
        $page = 1;

        do {
            $clone = clone $this;
            $clone->forPage($page, $size);
            $clone->applyAllFilters();

            $rows = $clone->executeQuery()->fetchAllAssociative();

            if ($rows === []) {
                break;
            }

            $collection = $clone->hydrateCollection($rows);

            if ($callback($collection) === false) {
                break;
            }

            $page++;
        } while (count($rows) === $size);
    }

    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void
    {
        $lastId = null;

        do {
            $clone = clone $this;
            $clone->orderBy($column, 'ASC');
            $clone->limit($size);

            if ($lastId !== null) {
                $param = $clone->buildParameterName($column . '_chunk');
                $clone->qb->setParameter($param, $lastId);
                $clone->qb->andWhere(sprintf('%s > :%s', $column, $param));
            }

            $clone->applyAllFilters();

            $rows = $clone->executeQuery()->fetchAllAssociative();

            if ($rows === []) {
                break;
            }

            $collection = $clone->hydrateCollection($rows);

            if ($callback($collection) === false) {
                break;
            }

            $lastRow = end($rows);
            $lastId  = $lastRow[$column] ?? null;
        } while (count($rows) === $size);
    }

    public function update(array $changes): int
    {
        $updateQb = new \Weaver\ORM\DBAL\QueryBuilder()
            ->update($this->mapper->getTableName());

        foreach ($changes as $column => $value) {
            $param = $this->buildParameterName($column);
            $updateQb->set($column, ':' . $param);
            $updateQb->setParameter($param, $value);
        }

        $this->copyWhereToBuilder($updateQb);

        $sql = $updateQb->getSQL();
        $params = $updateQb->getParameters();
        $types = $updateQb->getParameterTypes();
        $stmt = $this->connection->prepare($sql);
        $index = 1;
        foreach ($params as $key => $val) {
            $type = $types[$key] ?? \PDO::PARAM_STR;
            if ($type instanceof ParameterType) {
                $type = $type->value;
            } elseif (is_string($type)) {
                $type = \PDO::PARAM_STR;
            }
            $stmt->bindValue($index++, $val, $type);
        }
        return $stmt->executeStatement();
    }

    public function delete(): int
    {
        $deleteQb = new \Weaver\ORM\DBAL\QueryBuilder()
            ->delete($this->mapper->getTableName());

        $this->copyWhereToBuilder($deleteQb);

        $sql = $deleteQb->getSQL();
        $params = $deleteQb->getParameters();
        $types = $deleteQb->getParameterTypes();
        $stmt = $this->connection->prepare($sql);
        $index = 1;
        foreach ($params as $key => $val) {
            $type = $types[$key] ?? \PDO::PARAM_STR;
            if ($type instanceof ParameterType) {
                $type = $type->value;
            } elseif (is_string($type)) {
                $type = \PDO::PARAM_STR;
            }
            $stmt->bindValue($index++, $val, $type);
        }
        return $stmt->executeStatement();
    }

    public function insert(array $data): void
    {
        foreach ($data as $row) {
            $this->connection->insert($this->mapper->getTableName(), $row);
        }
    }

    public function upsert(array $data, array $uniqueBy, array $updateColumns): void
    {
        if ($data === []) {
            return;
        }

        $table    = $this->mapper->getTableName();
        $columns  = array_keys($data[0]);
        $platformName = $this->connection->getDatabasePlatform()->getName();

        $isPostgres = $platformName === 'postgresql' || $platformName === 'pyrosql';

        $placeholderRows = [];
        $params          = [];
        $types           = [];
        $i               = 0;

        foreach ($data as $row) {
            $placeholders = [];

            foreach ($columns as $col) {
                $paramName          = 'u_' . $i++;
                $placeholders[]     = ':' . $paramName;
                $params[$paramName] = $row[$col] ?? null;
            }

            $placeholderRows[] = '(' . implode(', ', $placeholders) . ')';
        }

        $colList = implode(', ', $columns);
        $valList = implode(', ', $placeholderRows);

        if ($isPostgres) {
            $conflictCols   = implode(', ', $uniqueBy);
            $updateClauses  = array_map(
                static fn (string $c): string => sprintf('%s = EXCLUDED.%s', $c, $c),
                $updateColumns,
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
                $table,
                $colList,
                $valList,
                $conflictCols,
                implode(', ', $updateClauses),
            );
        } else {
            $updateClauses = array_map(
                static fn (string $c): string => sprintf('%s = VALUES(%s)', $c, $c),
                $updateColumns,
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
                $table,
                $colList,
                $valList,
                implode(', ', $updateClauses),
            );
        }

        $this->connection->executeStatement($sql, $params, $types);
    }

    public function paginate(int $page = 1, int $perPage = 15, array $with = []): Page
    {
        $total = $this->count();

        $items = (clone $this)
            ->forPage($page, $perPage)
            ->get($with);

        return Page::create($items, $total, $page, $perPage);
    }

    public function simplePaginate(int $page = 1, int $perPage = 15, array $with = []): SimplePage
    {
        $clone = (clone $this)->forPage($page, $perPage + 1);
        $clone->applyAllFilters();

        $rows        = $clone->executeQuery()->fetchAllAssociative();
        $hasMore     = count($rows) > $perPage;

        if ($hasMore) {
            array_pop($rows);
        }

        $collection = $this->hydrateCollection($rows);
        $this->eagerLoadRelations($collection, array_merge($this->eagerLoads, $with));

        return new SimplePage(
            items:        $collection,
            currentPage:  $page,
            perPage:      $perPage,
            hasMorePages: $hasMore,
        );
    }

    private function copyWhereToBuilder(\Weaver\ORM\DBAL\QueryBuilder $target): void
    {
        $selectSql = $this->qb->getSQL();

        if (preg_match('/\bWHERE\b(.+?)(?:\bORDER\s+BY\b|\bGROUP\s+BY\b|\bHAVING\b|\bLIMIT\b|\bFOR\s+UPDATE\b|$)/si', $selectSql, $m)) {
            $target->where(trim($m[1]));
        }

        foreach ($this->qb->getParameters() as $key => $val) {
            $type = $this->qb->getParameterTypes()[$key] ?? null;

            $type !== null
                ? $target->setParameter($key, $val, $type)
                : $target->setParameter($key, $val);
        }
    }

    private function resolveImplicitJoin(string $relationName): string
    {
        if (isset($this->implicitJoins[$relationName])) {
            return $this->implicitJoins[$relationName];
        }

        $relation = $this->mapper->getRelation($relationName);

        if ($relation === null) {

            return $relationName;
        }

        $relatedMapper = new ($relation->getRelatedMapper())();
        $relatedTable  = $relatedMapper->getTableName();
        $joinAlias     = 'rel_' . $relationName;

        $relType = $relation->getType();

        if ($relType === \Weaver\ORM\Mapping\RelationType::BelongsTo) {

            $localFk  = $relation->getForeignKey() ?? ($relationName . '_id');
            $ownerKey = $relation->getOwnerKey() ?? $relatedMapper->getPrimaryKey();
            $condition = sprintf('%s.%s = %s.%s', $this->alias, $localFk, $joinAlias, $ownerKey);
        } else {

            $localPk   = $this->mapper->getPrimaryKey();
            $foreignKey = $relation->getForeignKey() ?? ($this->mapper->getTableName() . '_id');
            $condition  = sprintf('%s.%s = %s.%s', $joinAlias, $foreignKey, $this->alias, $localPk);
        }

        $this->qb->leftJoin($this->alias, $relatedTable, $joinAlias, $condition);
        $this->implicitJoins[$relationName] = $joinAlias;

        return $joinAlias;
    }

    private function buildWhereClause(string $column, string $operator, mixed $value): string
    {

        if (in_array(strtoupper($operator), ['IS', 'IS NOT'], true)) {
            $strValue = is_scalar($value) ? (string) $value : '';
            $literal = match (true) {
                $value === null              => 'NULL',
                strtolower($strValue) === 'null' => 'NULL',
                default                      => $strValue,
            };

            return sprintf('%s %s %s', $column, $operator, $literal);
        }

        // Auto-convert = NULL to IS NULL and != NULL to IS NOT NULL
        if ($value === null) {
            if ($operator === '=' || $operator === '==') {
                return sprintf('%s IS NULL', $column);
            }
            if ($operator === '!=' || $operator === '<>') {
                return sprintf('%s IS NOT NULL', $column);
            }
        }

        $param = $this->buildParameterName($column);
        $this->qb->setParameter($param, $value);

        return sprintf('%s %s :%s', $column, $operator, $param);
    }

    private function normalizeOperatorValue(mixed $operatorOrValue, mixed $value): array
    {
        $validOperators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];

        if ($value === null && !in_array(strtoupper(is_scalar($operatorOrValue) ? (string) $operatorOrValue : ''), $validOperators, true)) {

            return ['=', $operatorOrValue];
        }

        return [strtoupper(is_scalar($operatorOrValue) ? (string) $operatorOrValue : ''), $value];
    }

    private function buildParameterName(string $column): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $column);

        return 'param_' . $safe . '_' . ($this->paramCount++);
    }

    private function applySoftDeleteFilter(): void
    {
        if ($this->softDeleteApplied) {
            return;
        }

        $this->softDeleteApplied = true;

        if (!$this->mapper->getColumnByName('deleted_at') instanceof \Weaver\ORM\Mapping\ColumnDefinition) {
            return;
        }

        if ($this->withTrashed) {
            return;
        }

        $col = $this->alias . '.deleted_at';

        if ($this->onlyTrashed) {
            $this->qb->andWhere($col . ' IS NOT NULL');
        } else {
            $this->qb->andWhere($col . ' IS NULL');
        }
    }

    private function applyAllFilters(): void
    {
        $this->applyFilters();
        $this->applySoftDeleteFilter();
    }

    private function applyMaxRows(): void
    {
        if ($this->maxRows === null) {
            return;
        }

        $current = $this->qb->getMaxResults();

        if ($current === null || $current > $this->maxRows) {
            $this->qb->setMaxResults($this->maxRows);
        }
    }

    private function buildSQL(): string
    {
        $sql = $this->qb->getSQL();

        if ($this->comment !== null) {
            $sql = '/* ' . str_replace('*/', '', $this->comment) . ' */ ' . $sql;
        }

        if ($this->lockMode !== null) {
            $sql .= ' ' . $this->lockMode;
        }

        if ($this->ctes === []) {
            return $sql;
        }

        $keyword   = $this->recursiveCte ? 'WITH RECURSIVE' : 'WITH';
        $cteClauses = [];

        foreach ($this->ctes as $cteName => $cte) {
            $cteClauses[] = $cteName . ' AS (' . $cte['sql'] . ')';
        }

        return $keyword . ' ' . implode(', ', $cteClauses) . ' ' . $sql;
    }

    private function collectAllParams(): array
    {
        if ($this->ctes === []) {
            return $this->qb->getParameters();
        }

        $params = [];

        foreach ($this->ctes as $cte) {
            foreach ($cte['bindings'] as $key => $value) {
                $params[$key] = $value;
            }
        }

        foreach ($this->qb->getParameters() as $key => $value) {
            $params[$key] = $value;
        }

        return $params;
    }

    private function executeQuery(): \Weaver\ORM\DBAL\Result
    {
        if ($this->unions !== []) {
            [$sql, $positionalParams] = $this->buildUnionSQL();

            if ($this->comment !== null) {
                $sql = '/* ' . str_replace('*/', '', $this->comment) . ' */ ' . $sql;
            }

            $stmt = $this->connection->prepare($sql);
            foreach ($positionalParams as $i => $val) {
                if ($val instanceof \DateTimeInterface) {
                    $val = $val->format('Y-m-d H:i:s');
                }
                $stmt->bindValue($i + 1, $val);
            }
            return $stmt->execute();
        }

        $sql    = $this->buildSQL();
        $params = $this->collectAllParams();
        $types  = $this->qb->getParameterTypes();

        $stmt = $this->connection->prepare($sql);
        $index = 1;
        foreach ($params as $key => $val) {
            $type = $types[$key] ?? \PDO::PARAM_STR;
            if ($type instanceof ParameterType) {
                $type = $type->value;
            } elseif (is_string($type)) {
                $type = \PDO::PARAM_STR;
            }
            // Convert DateTimeInterface objects to string for PDO binding
            if ($val instanceof \DateTimeInterface) {
                $val = $val->format('Y-m-d H:i:s');
            }
            $stmt->bindValue($index++, $val, $type);
        }
        return $stmt->execute();
    }

    private function buildUnionSQL(): array
    {
        $baseSql        = $this->qb->getSQL();
        $namedParams    = $this->qb->getParameters();
        $positional     = [];

        $convertedSql = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use ($namedParams, &$positional): string {
                $key = $m[1];
                $positional[] = $namedParams[$key] ?? null;

                return '?';
            },
            $baseSql,
        );

        assert(is_string($convertedSql));

        $parts = [$convertedSql];

        foreach ($this->unions as $union) {
            $keyword = $union['all'] ? 'UNION ALL' : 'UNION';
            $parts[] = $keyword . ' ' . $union['sql'];

            foreach ($union['bindings'] as $binding) {
                $positional[] = $binding;
            }
        }

        return [implode(' ', $parts), $positional];
    }

    private function resolveUnionQuery(string|\Closure $query, array $bindings): array
    {
        if ($query instanceof \Closure) {
            $dbalQb = new \Weaver\ORM\DBAL\QueryBuilder();
            $query($dbalQb);

            $rawSql      = $dbalQb->getSQL();
            $namedParams = $dbalQb->getParameters();
            $positional  = [];

            $sql = preg_replace_callback(
                '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
                static function (array $m) use ($namedParams, &$positional): string {
                    $key = $m[1];
                    $positional[] = $namedParams[$key] ?? null;

                    return '?';
                },
                $rawSql,
            );

            assert(is_string($sql));

            return [$sql, $positional];
        }

        return [$query, array_values($bindings)];
    }

    private function hydrateOne(array $row): object
    {
        if ($this->hydrator instanceof \Weaver\ORM\Hydration\EntityHydrator) {
            return $this->hydrator->hydrate($this->entityClass, $row);
        }

        return (object) $row;
    }

    private function hydrateCollection(array $rows): EntityCollection
    {
        if ($this->hydrator instanceof \Weaver\ORM\Hydration\EntityHydrator) {
            return $this->hydrator->hydrateMany($this->entityClass, $rows);
        }

        $entities = array_map(static fn (array $row): object => (object) $row, $rows);

        return new EntityCollection($entities);
    }

    private function eagerLoadRelations(EntityCollection $collection, array $relations): void
    {
        if (!$this->relationLoader instanceof \Weaver\ORM\Relation\RelationLoader || $relations === [] || $collection->isEmpty()) {
            return;
        }

        $this->relationLoader->load($collection, $this->entityClass, $relations);
    }

    private function newSubBuilder(): static
    {
        $sub             = clone $this;
        $sub->qb         = new \Weaver\ORM\DBAL\QueryBuilder();
        $sub->paramCount = $this->paramCount;

        $sub->qb->select('1')->from($this->mapper->getTableName(), $this->alias);

        return $sub;
    }

    private function mergeParameters(self $sub): void
    {
        $this->paramCount = $sub->paramCount;

        foreach ($sub->qb->getParameters() as $key => $value) {
            $type = $sub->qb->getParameterTypes()[$key] ?? null;

            $type !== null
                ? $this->qb->setParameter($key, $value, $type)
                : $this->qb->setParameter($key, $value);
        }
    }

    private function applyWithCounts(EntityCollection $collection, array $rows): void
    {
        if ($this->withCounts === [] || $rows === []) {
            return;
        }

        $entities = $collection->toArray();

        foreach ($entities as $index => $entity) {
            if (!isset($rows[$index])) {
                continue;
            }

            $row = $rows[$index];

            foreach ($this->withCounts as $spec) {
                $alias = $spec['alias'];

                if (array_key_exists($alias, $row)) {
                    $entity->$alias = (int) $row[$alias];
                }
            }
        }
    }

    private function buildOverClause(
        ?string $partitionBy,
        ?string $orderBy,
        ?array $frameClause,
    ): string {
        $parts = [];

        if ($partitionBy !== null && $partitionBy !== '') {
            $parts[] = 'PARTITION BY ' . $partitionBy;
        }

        if ($orderBy !== null && $orderBy !== '') {
            $parts[] = 'ORDER BY ' . $orderBy;
        }

        if ($frameClause !== null && count($frameClause) >= 2) {
            $type  = $frameClause[0];
            $start = $frameClause[1];

            if (isset($frameClause[2])) {
                $parts[] = $type . ' BETWEEN ' . $start . ' AND ' . $frameClause[2];
            } else {
                $parts[] = $type . ' ' . $start;
            }
        }

        return 'OVER (' . implode(' ', $parts) . ')';
    }

    public function selectWindow(
        string $function,
        string $alias,
        ?string $partitionBy = null,
        ?string $orderBy = null,
        ?array $frameClause = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, $frameClause);
        $expr = $function . '() ' . $over . ' AS ' . $alias;

        $this->qb->addSelect($expr);

        return $this;
    }

    public function rowNumber(string $alias, ?string $partitionBy = null, ?string $orderBy = null): static
    {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('ROW_NUMBER() ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function rank(string $alias, ?string $partitionBy = null, string $orderBy = 'id'): static
    {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('RANK() ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function denseRank(string $alias, ?string $partitionBy = null, string $orderBy = 'id'): static
    {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('DENSE_RANK() ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function lag(
        string $column,
        string $alias,
        int $offset = 1,
        mixed $default = null,
        ?string $partitionBy = null,
        ?string $orderBy = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);

        if ($default !== null) {
            $defaultSql = is_string($default) ? "'" . addslashes($default) . "'" : (string) $default;
            $fn = 'LAG(' . $column . ', ' . $offset . ', ' . $defaultSql . ')';
        } else {
            $fn = 'LAG(' . $column . ', ' . $offset . ')';
        }

        $this->qb->addSelect($fn . ' ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function lead(
        string $column,
        string $alias,
        int $offset = 1,
        mixed $default = null,
        ?string $partitionBy = null,
        ?string $orderBy = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);

        if ($default !== null) {
            $defaultSql = is_string($default) ? "'" . addslashes($default) . "'" : (string) $default;
            $fn = 'LEAD(' . $column . ', ' . $offset . ', ' . $defaultSql . ')';
        } else {
            $fn = 'LEAD(' . $column . ', ' . $offset . ')';
        }

        $this->qb->addSelect($fn . ' ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function sumOver(
        string $column,
        string $alias,
        ?string $partitionBy = null,
        ?string $orderBy = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('SUM(' . $column . ') ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function avgOver(
        string $column,
        string $alias,
        ?string $partitionBy = null,
        ?string $orderBy = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('AVG(' . $column . ') ' . $over . ' AS ' . $alias);

        return $this;
    }

    public function countOver(
        string $column,
        string $alias,
        ?string $partitionBy = null,
        ?string $orderBy = null,
    ): static {
        $over = $this->buildOverClause($partitionBy, $orderBy, null);
        $this->qb->addSelect('COUNT(' . $column . ') ' . $over . ' AS ' . $alias);

        return $this;
    }

    private function resolveSubquery(string|\Closure $query, string $context, array $bindings): array
    {
        if ($query instanceof \Closure) {
            $sub = $this->newSubBuilder();
            $dbalQb = new \Weaver\ORM\DBAL\QueryBuilder();
            $query($dbalQb);
            $subSql      = $dbalQb->getSQL();
            $subBindings = [];

            foreach ($dbalQb->getParameters() as $key => $value) {
                $subBindings[$key] = $value;
            }

            unset($sub);

            return [$subSql, $subBindings];
        }

        return [$query, $bindings];
    }

    public function getDbalQueryBuilder(): \Weaver\ORM\DBAL\QueryBuilder
    {
        return $this->qb;
    }

    public function getConnection(): \Weaver\ORM\DBAL\Connection
    {
        return $this->connection;
    }

    private function resolveQueryCacheKey(): string
    {
        if ($this->cacheKey !== null) {
            return $this->cacheKey;
        }

        $sql = $this->buildSQL();
        $params = $this->collectAllParams();

        return md5($sql . ':' . serialize($params));
    }

    /**
     * Resolve a property name to its database column name.
     * Allows using camelCase property names in where() calls.
     */
    private function resolveColumnName(string $name): string
    {
        if (str_contains($name, '.') || str_contains($name, ' ')) {
            return $name;
        }
        $col = $this->mapper->getColumn($name);
        if ($col !== null) {
            return $col->column;
        }
        return $name;
    }
}
