<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Collection;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Collection\EntityCollection;

class SimpleEntity
{
    public function __construct(
        public int $id,
        public string $name,
        public int $age,
    ) {}
}

final class EntityCollectionTest extends TestCase
{




    private function makeCollection(): EntityCollection
    {
        return new EntityCollection([
            new SimpleEntity(1, 'Alice', 30),
            new SimpleEntity(2, 'Bob',   25),
            new SimpleEntity(3, 'Carol', 35),
        ]);
    }



    public function test_count_returns_item_count(): void
    {
        $col = $this->makeCollection();

        self::assertCount(3, $col);
        self::assertSame(3, $col->count());
    }

    public function test_is_empty_and_is_not_empty(): void
    {
        $empty    = new EntityCollection([]);
        $nonEmpty = $this->makeCollection();

        self::assertTrue($empty->isEmpty());
        self::assertFalse($empty->isNotEmpty());

        self::assertFalse($nonEmpty->isEmpty());
        self::assertTrue($nonEmpty->isNotEmpty());
    }

    public function test_first_and_last(): void
    {
        $col = $this->makeCollection();

        $first = $col->first();
        $last  = $col->last();

        self::assertNotNull($first);
        self::assertInstanceOf(SimpleEntity::class, $first);
        self::assertSame('Alice', $first->name);

        self::assertNotNull($last);
        self::assertInstanceOf(SimpleEntity::class, $last);
        self::assertSame('Carol', $last->name);
    }

    public function test_first_on_empty_returns_null(): void
    {
        $col = new EntityCollection([]);

        self::assertNull($col->first());
    }

    public function test_filter_returns_new_collection(): void
    {
        $col      = $this->makeCollection();
        $filtered = $col->filter(static fn (SimpleEntity $e): bool => $e->age > 25);

        self::assertInstanceOf(EntityCollection::class, $filtered);
        self::assertCount(2, $filtered);

        self::assertCount(3, $col);
    }

    public function test_map_returns_array(): void
    {
        $col    = $this->makeCollection();
        $result = $col->map(static fn (SimpleEntity $e): string => $e->name);

        self::assertIsArray($result);
        self::assertSame(['Alice', 'Bob', 'Carol'], $result);
    }

    public function test_pluck_extracts_property(): void
    {
        $col   = $this->makeCollection();
        $names = $col->pluck('name');

        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
    }

    public function test_pluck_with_key_by(): void
    {
        $col    = $this->makeCollection();
        $result = $col->pluck('name', 'id');

        self::assertSame([1 => 'Alice', 2 => 'Bob', 3 => 'Carol'], $result);
    }

    public function test_key_by_indexes_by_property(): void
    {
        $col    = $this->makeCollection();
        $keyed  = $col->keyBy('id');

        self::assertArrayHasKey(1, $keyed);
        self::assertArrayHasKey(2, $keyed);
        self::assertArrayHasKey(3, $keyed);
        self::assertSame('Alice', $keyed[1]->name);
        self::assertSame('Bob',   $keyed[2]->name);
        self::assertSame('Carol', $keyed[3]->name);
    }

    public function test_group_by_groups_correctly(): void
    {
        $col = new EntityCollection([
            new SimpleEntity(1, 'Alice', 30),
            new SimpleEntity(2, 'Bob',   25),
            new SimpleEntity(3, 'Carol', 30),
        ]);

        $groups = $col->groupBy('age');

        self::assertArrayHasKey(30, $groups);
        self::assertArrayHasKey(25, $groups);
        self::assertCount(2, $groups[30]);
        self::assertCount(1, $groups[25]);
    }

    public function test_sort_by_asc_and_desc(): void
    {
        $col = $this->makeCollection();

        $asc  = $col->sortBy('age');
        $desc = $col->sortBy('age', 'desc');

        $ascAges  = $asc->pluck('age');
        $descAges = $desc->pluck('age');

        self::assertSame([25, 30, 35], $ascAges);
        self::assertSame([35, 30, 25], $descAges);
    }

    public function test_chunk_splits_into_batches(): void
    {
        $col = new EntityCollection([
            new SimpleEntity(1, 'A', 20),
            new SimpleEntity(2, 'B', 21),
            new SimpleEntity(3, 'C', 22),
            new SimpleEntity(4, 'D', 23),
            new SimpleEntity(5, 'E', 24),
        ]);

        $chunks = $col->chunk(2);

        self::assertCount(3, $chunks);
        self::assertCount(2, $chunks[0]);
        self::assertCount(2, $chunks[1]);
        self::assertCount(1, $chunks[2]);
    }

    public function test_sum_avg_min_max(): void
    {
        $col = $this->makeCollection();

        self::assertSame(90, $col->sum('age'));
        self::assertEqualsWithDelta(30.0, $col->avg('age'), 0.001);
        self::assertSame(25, $col->min('age'));
        self::assertSame(35, $col->max('age'));
    }

    public function test_contains_by_reference(): void
    {
        $entity = new SimpleEntity(1, 'Alice', 30);
        $other  = new SimpleEntity(1, 'Alice', 30);
        $col    = new EntityCollection([$entity]);

        self::assertTrue($col->contains($entity));
        self::assertFalse($col->contains($other));
    }

    public function test_first_where(): void
    {
        $col    = $this->makeCollection();
        $result = $col->firstWhere('id', 2);

        self::assertNotNull($result);
        self::assertInstanceOf(SimpleEntity::class, $result);
        self::assertSame('Bob', $result->name);

        $notFound = $col->firstWhere('id', 99);
        self::assertNull($notFound);
    }

    public function test_unique_removes_duplicates(): void
    {
        $col = new EntityCollection([
            new SimpleEntity(1, 'Alice', 30),
            new SimpleEntity(2, 'Bob',   30),
            new SimpleEntity(3, 'Carol', 25),
        ]);

        $unique = $col->unique('age');


        self::assertCount(2, $unique);
        $ages = $unique->pluck('age');
        self::assertContains(30, $ages);
        self::assertContains(25, $ages);
    }

    public function test_merge(): void
    {
        $a = new EntityCollection([new SimpleEntity(1, 'Alice', 30)]);
        $b = new EntityCollection([new SimpleEntity(2, 'Bob',   25)]);

        $merged = $a->merge($b);

        self::assertCount(2, $merged);

        self::assertCount(1, $a);
        self::assertCount(1, $b);
    }

    public function test_slice(): void
    {
        $col    = $this->makeCollection();
        $sliced = $col->slice(1, 2);

        self::assertCount(2, $sliced);
        self::assertSame('Bob',   $sliced->first()->name);
        self::assertSame('Carol', $sliced->last()->name);
    }

    public function test_ids_shortcut(): void
    {
        $col = $this->makeCollection();

        self::assertSame([1, 2, 3], $col->ids());
    }

    public function test_iteration_via_foreach(): void
    {
        $col      = $this->makeCollection();
        $iterated = [];

        foreach ($col as $entity) {
            $iterated[] = $entity->name;
        }

        self::assertSame(['Alice', 'Bob', 'Carol'], $iterated);
    }

    public function test_json_serialize(): void
    {
        $col    = $this->makeCollection();
        $result = $col->jsonSerialize();

        self::assertIsArray($result);
        self::assertCount(3, $result);

        self::assertInstanceOf(SimpleEntity::class, $result[0]);
        self::assertSame('Alice', $result[0]->name);
    }
}
