<?php

declare(strict_types=1);

namespace Weaver\ORM\Mapping;

final readonly class RelationDefinition
{

    public function __construct(
        private string $property,
        private RelationType $type,
        private string $relatedEntity,
        private string $relatedMapper,
        private ?string $foreignKey = null,
        private ?string $ownerKey = null,
        private ?string $pivotTable = null,
        private ?string $pivotForeignKey = null,
        private ?string $pivotRelatedKey = null,
        private array $pivotColumns = [],
        private bool $pivotTimestamps = false,
        private ?string $morphType = null,
        private ?string $morphId = null,
        private ?string $morphClass = null,
        private ?string $throughEntity = null,
        private ?string $throughMapper = null,
        private ?string $throughForeignKey = null,
        private ?string $throughLocalKey = null,
        private array $cascade = [],
        private bool $orphanRemoval = false,
        private array $orderBy = [],
        private string $inversedBy = '',
        private string $mappedBy = '',
    ) {}

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getType(): RelationType
    {
        return $this->type;
    }

    public function getRelatedEntity(): string
    {
        return $this->relatedEntity;
    }

    public function getRelatedMapper(): string
    {
        return $this->relatedMapper;
    }

    public function getForeignKey(): ?string
    {
        return $this->foreignKey;
    }

    public function getOwnerKey(): ?string
    {
        return $this->ownerKey;
    }

    public function getPivotTable(): ?string
    {
        return $this->pivotTable;
    }

    public function getPivotForeignKey(): ?string
    {
        return $this->pivotForeignKey;
    }

    public function getPivotRelatedKey(): ?string
    {
        return $this->pivotRelatedKey;
    }

    public function getPivotColumns(): array
    {
        return $this->pivotColumns;
    }

    public function hasPivotTimestamps(): bool
    {
        return $this->pivotTimestamps;
    }

    public function getMorphType(): ?string
    {
        return $this->morphType;
    }

    public function getMorphId(): ?string
    {
        return $this->morphId;
    }

    public function getMorphClass(): ?string
    {
        return $this->morphClass;
    }

    public function getThroughEntity(): ?string
    {
        return $this->throughEntity;
    }

    public function getThroughMapper(): ?string
    {
        return $this->throughMapper;
    }

    public function getThroughForeignKey(): ?string
    {
        return $this->throughForeignKey;
    }

    public function getThroughLocalKey(): ?string
    {
        return $this->throughLocalKey;
    }

    public function getCascade(): array
    {
        return $this->cascade;
    }

    public function isOrphanRemoval(): bool
    {
        return $this->orphanRemoval;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    public function getInversedBy(): string
    {
        return $this->inversedBy;
    }

    public function getMappedBy(): string
    {
        return $this->mappedBy;
    }

    public function isOwningSide(): bool
    {
        return $this->inversedBy !== '';
    }

    public function isInverseSide(): bool
    {
        return $this->mappedBy !== '';
    }

    public function isBidirectional(): bool
    {
        return $this->inversedBy !== '' || $this->mappedBy !== '';
    }

    public function hasCascade(CascadeType $type): bool
    {
        foreach ($this->cascade as $entry) {
            if ($entry === CascadeType::All || $entry === $type) {
                return true;
            }
        }

        return false;
    }
}
