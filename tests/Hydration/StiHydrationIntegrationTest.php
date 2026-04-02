<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Hydration;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\TypeColumn;
use Weaver\ORM\Mapping\Attribute\TypeMap;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\Inheritance;

#[Entity(table: 'animals')]
#[Inheritance('SINGLE_TABLE')]
#[TypeColumn(name: 'animal_type')]
#[TypeMap(['dog' => StiDog::class, 'cat' => StiCat::class])]
class StiAnimal
{
    #[Id] public int $id = 0;
    #[Column] public string $name = '';
}

#[Entity(table: 'animals')]
class StiDog extends StiAnimal
{
    #[Column] public string $breed = '';
}

#[Entity(table: 'animals')]
class StiCat extends StiAnimal
{
    #[Column] public bool $indoor = false;
}

#[Entity(table: 'plain_things')]
class StiPlainThing
{
    #[Id] public int $id = 0;
    #[Column] public string $label = '';
}

final class StiHydrationIntegrationTest extends TestCase
{
    private EntityHydrator $hydrator;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $factory = new AttributeMapperFactory();

        $this->registry = new MapperRegistry();
        $this->registry->register($factory->build(StiAnimal::class));
        $this->registry->register($factory->build(StiPlainThing::class));

        $this->hydrator = new EntityHydrator($this->registry, $connection);
    }





    public function test_hydrating_dog_discriminator_returns_dog_instance(): void
    {
        $row = ['id' => 1, 'name' => 'Rex', 'animal_type' => 'dog'];

        $entity = $this->hydrator->hydrate(StiAnimal::class, $row);

        $this->assertInstanceOf(StiDog::class, $entity);
    }





    public function test_hydrating_cat_discriminator_returns_cat_instance(): void
    {
        $row = ['id' => 2, 'name' => 'Whiskers', 'animal_type' => 'cat'];

        $entity = $this->hydrator->hydrate(StiAnimal::class, $row);

        $this->assertInstanceOf(StiCat::class, $entity);
    }





    public function test_unknown_discriminator_falls_back_to_base_class(): void
    {
        $row = ['id' => 3, 'name' => 'Unknown', 'animal_type' => 'fish'];

        $entity = $this->hydrator->hydrate(StiAnimal::class, $row);

        $this->assertInstanceOf(StiAnimal::class, $entity);

        $this->assertSame(StiAnimal::class, $entity::class);
    }





    public function test_missing_discriminator_column_returns_base_class(): void
    {
        $row = ['id' => 4, 'name' => 'NoType'];

        $entity = $this->hydrator->hydrate(StiAnimal::class, $row);

        $this->assertInstanceOf(StiAnimal::class, $entity);
        $this->assertSame(StiAnimal::class, $entity::class);
    }





    public function test_parent_mapper_columns_are_set_on_subclass_instance(): void
    {
        $row = ['id' => 5, 'name' => 'Buddy', 'animal_type' => 'dog'];


        $entity = $this->hydrator->hydrate(StiAnimal::class, $row);

        $this->assertInstanceOf(StiDog::class, $entity);
        $this->assertSame(5, $entity->id);
        $this->assertSame('Buddy', $entity->name);
    }





    public function test_non_sti_entity_hydrates_normally(): void
    {
        $row = ['id' => 10, 'label' => 'hello'];

        $entity = $this->hydrator->hydrate(StiPlainThing::class, $row);

        $this->assertInstanceOf(StiPlainThing::class, $entity);
        $this->assertSame(StiPlainThing::class, $entity::class);
        $this->assertSame(10, $entity->id);
        $this->assertSame('hello', $entity->label);
    }
}
