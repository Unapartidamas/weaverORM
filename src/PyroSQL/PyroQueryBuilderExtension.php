<?php

declare(strict_types=1);

namespace Weaver\ORM\PyroSQL;

use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Query\EntityQueryBuilder;
use Weaver\ORM\Relation\RelationLoader;
use Weaver\ORM\PyroSQL\Approximate\ApproximateQueryBuilder;
use Weaver\ORM\PyroSQL\FullText\FullTextSearch;
use Weaver\ORM\PyroSQL\Geo\GeoSearch;
use Weaver\ORM\PyroSQL\Query\TimeTravelQueryBuilder;

trait PyroQueryBuilderExtension
{

    abstract protected function getConnection(): \Weaver\ORM\DBAL\Connection;

    abstract protected function getMapper(): AbstractEntityMapper;

    abstract protected function getHydrator(): EntityHydrator;

    abstract protected function getRelationLoader(): RelationLoader;

    public function queryAsOf(\DateTimeImmutable $timestamp): TimeTravelQueryBuilder
    {
        $mapper = $this->getMapper();

        $inner = new EntityQueryBuilder(
            connection:     $this->getConnection(),
            entityClass:    $mapper->getEntityClass(),
            mapper:         $mapper,
            hydrator:       $this->getHydrator(),
            relationLoader: $this->getRelationLoader(),
        );

        return (new TimeTravelQueryBuilder($inner, $mapper->getTableName()))
            ->asOf($timestamp);
    }

    public function approximate(float $within = 5.0, float $confidence = 95.0): ApproximateQueryBuilder
    {

        $qb = $this->query();

        return new ApproximateQueryBuilder(
            inner:      $qb,
            connection: $this->getConnection(),
            within:     $within,
            confidence: $confidence,
        );
    }

    public function search(string $column, string $query): self
    {
        $mapper = $this->getMapper();
        $table = $mapper->getTableName();
        $this->getConnection()->executeStatement(
            FullTextSearch::search($table, $column, $query)
        );

        return $this;
    }

    public function nearestGeo(string $column, float $lat, float $lon, int $k = 10): self
    {
        $this->getConnection()->executeStatement(
            GeoSearch::nearestPoints($column, $lat, $lon, $k)
        );

        return $this;
    }

    public function withinRadius(string $column, float $lat, float $lon, float $meters): self
    {
        $this->getConnection()->executeStatement(
            GeoSearch::withinRadius($column, $lat, $lon, $meters)
        );

        return $this;
    }

    public function onBranch(string $branch): self
    {
        $this->getConnection()->executeStatement(
            "SET pyrosql.branch = '{$branch}'"
        );

        return $this;
    }
}
