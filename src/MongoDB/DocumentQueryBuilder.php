<?php

declare(strict_types=1);

namespace Weaver\ORM\MongoDB;

use Weaver\ORM\Collection\EntityCollection;
use Weaver\ORM\MongoDB\Exception\DocumentNotFoundException;
use Weaver\ORM\MongoDB\Mapping\AbstractDocumentMapper;
use Weaver\ORM\Pagination\Page;

final class DocumentQueryBuilder
{

    private array $pipeline = [];

    private array $matchStage = [];

    private ?int $limitValue = null;
    private ?int $skipValue  = null;

    private array $sortStage = [];

    private array $projectStage = [];

    public function __construct(
        private readonly \MongoDB\Collection $collection,
        private readonly AbstractDocumentMapper $mapper,
    ) {}

    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if ($value === null) {

            $this->matchStage[$field] = $operatorOrValue;
        } else {
            $operator = (string) $operatorOrValue;

            if ($operator === '=') {
                $this->matchStage[$field] = $value;
            } elseif ($operator === 'like') {

                $pattern = str_replace('%', '.*', preg_quote((string) $value, '/'));
                $this->matchStage[$field] = ['$regex' => $pattern, '$options' => 'i'];
            } elseif ($operator === 'not like') {
                $pattern = str_replace('%', '.*', preg_quote((string) $value, '/'));
                $this->matchStage[$field] = ['$not' => ['$regex' => $pattern, '$options' => 'i']];
            } else {
                $mongoOp = $this->mapOperator($operator);
                $this->matchStage[$field] = [$mongoOp => $value];
            }
        }

        return $this;
    }

    public function orWhere(\Closure $fn): static
    {
        $sub = new self($this->collection, $this->mapper);
        $fn($sub);

        $existing = $this->matchStage['$or'] ?? [];

        foreach ($sub->matchStage as $field => $condition) {
            $existing[] = [$field => $condition];
        }

        $this->matchStage['$or'] = $existing;

        return $this;
    }

    public function whereIn(string $field, array $values): static
    {
        $this->matchStage[$field] = ['$in' => $values];

        return $this;
    }

    public function whereNotIn(string $field, array $values): static
    {
        $this->matchStage[$field] = ['$nin' => $values];

        return $this;
    }

    public function whereNull(string $field): static
    {
        $this->matchStage[$field] = ['$exists' => false];

        return $this;
    }

    public function whereNotNull(string $field): static
    {
        $this->matchStage[$field] = ['$exists' => true, '$ne' => null];

        return $this;
    }

    public function whereRegex(string $field, string $pattern, string $flags = ''): static
    {
        $condition = ['$regex' => $pattern];

        if ($flags !== '') {
            $condition['$options'] = $flags;
        }

        $this->matchStage[$field] = $condition;

        return $this;
    }

    public function whereText(string $search): static
    {
        $this->matchStage['$text'] = ['$search' => $search];

        return $this;
    }

    public function whereGeoNear(string $field, float $lat, float $lng, float $maxDistance): static
    {

        $radiusInRadians = $maxDistance / 6378137;

        $this->matchStage[$field] = [
            '$geoWithin' => [
                '$centerSphere' => [[$lng, $lat], $radiusInRadians],
            ],
        ];

        return $this;
    }

    public function whereArrayContains(string $field, mixed $value): static
    {
        $this->matchStage[$field] = $value;

        return $this;
    }

    public function whereElemMatch(string $field, array $conditions): static
    {
        $this->matchStage[$field] = ['$elemMatch' => $conditions];

        return $this;
    }

    public function project(array $fields): static
    {
        foreach ($fields as $field) {
            $this->projectStage[$field] = 1;
        }

        return $this;
    }

    public function projectExclude(array $fields): static
    {
        foreach ($fields as $field) {
            $this->projectStage[$field] = 0;
        }

        return $this;
    }

    public function sort(string $field, int $direction = 1): static
    {
        $this->sortStage[$field] = $direction;

        return $this;
    }

    public function limit(int $n): static
    {
        $this->limitValue = $n;

        return $this;
    }

    public function skip(int $n): static
    {
        $this->skipValue = $n;

        return $this;
    }

    public function forPage(int $page, int $perPage): static
    {
        $this->skipValue  = ($page - 1) * $perPage;
        $this->limitValue = $perPage;

        return $this;
    }

    public function group(array $groupStage): static
    {
        $this->pipeline[] = ['$group' => $groupStage];

        return $this;
    }

    public function unwind(string $field, bool $preserveNullAndEmpty = false): static
    {
        $fieldPath = str_starts_with($field, '$') ? $field : '$' . $field;

        if ($preserveNullAndEmpty) {
            $this->pipeline[] = [
                '$unwind' => [
                    'path'                       => $fieldPath,
                    'preserveNullAndEmptyArrays' => true,
                ],
            ];
        } else {
            $this->pipeline[] = ['$unwind' => $fieldPath];
        }

        return $this;
    }

    public function lookup(string $from, string $localField, string $foreignField, string $as): static
    {
        $this->pipeline[] = [
            '$lookup' => [
                'from'         => $from,
                'localField'   => $localField,
                'foreignField' => $foreignField,
                'as'           => $as,
            ],
        ];

        return $this;
    }

    public function addFields(array $fields): static
    {
        $this->pipeline[] = ['$addFields' => $fields];

        return $this;
    }

    public function facet(array $facets): static
    {
        $this->pipeline[] = ['$facet' => $facets];

        return $this;
    }

    public function get(): EntityCollection
    {
        $pipeline = $this->buildPipeline();
        $cursor   = $this->collection->aggregate($pipeline);
        $entities = [];

        foreach ($cursor as $doc) {
            $entities[] = $this->hydrateResult((array) $doc);
        }

        return new EntityCollection($entities);
    }

    public function first(): ?object
    {
        $clone             = clone $this;
        $clone->limitValue = 1;

        $pipeline = $clone->buildPipeline();
        $cursor   = $this->collection->aggregate($pipeline);

        foreach ($cursor as $doc) {
            return $this->hydrateResult((array) $doc);
        }

        return null;
    }

    public function firstOrFail(): object
    {
        $result = $this->first();

        if ($result === null) {
            throw DocumentNotFoundException::noResults($this->mapper->getDocumentClass());
        }

        return $result;
    }

    public function count(): int
    {
        $stages = [];

        if ($this->matchStage !== []) {
            $stages[] = ['$match' => $this->matchStage];
        }

        $stages[] = ['$count' => 'total'];

        $cursor = $this->collection->aggregate($stages);

        foreach ($cursor as $doc) {
            return (int) ($doc['total'] ?? 0);
        }

        return 0;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function cursor(): \Generator
    {
        $pipeline = $this->buildPipeline();
        $cursor   = $this->collection->aggregate($pipeline);

        foreach ($cursor as $doc) {
            yield $this->hydrateResult((array) $doc);
        }
    }

    public function distinct(string $field): array
    {
        $filter = $this->buildMatchStage();

        return $this->collection->distinct($field, $filter);
    }

    public function getRaw(): array
    {
        $pipeline = $this->buildPipeline();
        $cursor   = $this->collection->aggregate($pipeline);
        $results  = [];

        foreach ($cursor as $doc) {
            $results[] = (array) $doc;
        }

        return $results;
    }

    public function paginate(int $page = 1, int $perPage = 15): Page
    {
        $total = $this->count();

        $clone             = clone $this;
        $clone->skipValue  = ($page - 1) * $perPage;
        $clone->limitValue = $perPage;

        $items = $clone->get();

        return Page::create($items, $total, $page, $perPage);
    }

    public function update(array $update): int
    {
        $filter = $this->buildMatchStage();
        $result = $this->collection->updateMany($filter, ['$set' => $update]);

        return $result->getModifiedCount() ?? 0;
    }

    public function delete(): int
    {
        $filter = $this->buildMatchStage();
        $result = $this->collection->deleteMany($filter);

        return $result->getDeletedCount() ?? 0;
    }

    public function upsert(array $filter, array $update): void
    {
        $this->collection->updateOne($filter, ['$set' => $update], ['upsert' => true]);
    }

    public function toPipeline(): array
    {
        return $this->buildPipeline();
    }

    public function dump(): static
    {
        var_dump($this->buildPipeline());

        return $this;
    }

    public function dd(): never
    {
        var_dump($this->buildPipeline());
        exit(1);
    }

    private function buildMatchStage(): array
    {
        return $this->matchStage;
    }

    private function buildPipeline(): array
    {
        $stages = [];

        if ($this->matchStage !== []) {
            $stages[] = ['$match' => $this->matchStage];
        }

        if ($this->projectStage !== []) {
            $stages[] = ['$project' => $this->projectStage];
        }

        foreach ($this->pipeline as $stage) {
            $stages[] = $stage;
        }

        if ($this->sortStage !== []) {
            $stages[] = ['$sort' => $this->sortStage];
        }

        if ($this->skipValue !== null) {
            $stages[] = ['$skip' => $this->skipValue];
        }

        if ($this->limitValue !== null) {
            $stages[] = ['$limit' => $this->limitValue];
        }

        return $stages;
    }

    private function mapOperator(string $op): string
    {
        return match ($op) {
            '!='  => '$ne',
            '>'   => '$gt',
            '<'   => '$lt',
            '>='  => '$gte',
            '<='  => '$lte',
            default => throw new \InvalidArgumentException(
                sprintf('Unknown query operator "%s".', $op),
            ),
        };
    }

    private function hydrateResult(array $doc): object
    {
        return $this->mapper->hydrate($doc);
    }
}
