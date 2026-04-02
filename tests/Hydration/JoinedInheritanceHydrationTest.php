<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Hydration;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\AttributeEntityMapper;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\InheritanceMapping;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Mapping\Attribute\Column;
use Weaver\ORM\Mapping\Attribute\TypeColumn;
use Weaver\ORM\Mapping\Attribute\TypeMap;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\Attribute\Id;
use Weaver\ORM\Mapping\Attribute\Inheritance;

#[Entity(table: 'animals')]
#[Inheritance('JOINED')]
#[TypeColumn(name: 'type')]
#[TypeMap(['dog' => JoinedDog::class, 'cat' => JoinedCat::class])]
class JoinedAnimal
{
    public int $id = 0;
    public string $name = '';
}

#[Entity(table: 'dogs')]
class JoinedDog extends JoinedAnimal
{
    public string $breed = '';
}

#[Entity(table: 'cats')]
class JoinedCat extends JoinedAnimal
{
    public string $color = '';
}

final class JoinedAnimalMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return JoinedAnimal::class;
    }

    public function getTableName(): string
    {
        return 'animals';
    }



    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('name',  'name',  'string',  nullable: false, length: 255),
            new ColumnDefinition('type',  'type',  'string',  nullable: true,  length: 50),

            new ColumnDefinition('breed', 'breed', 'string',  nullable: true,  length: 255),

            new ColumnDefinition('color', 'color', 'string',  nullable: true,  length: 255),
        ];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        return new InheritanceMapping(
            type:                'JOINED',
            discriminatorColumn: 'type',
            discriminatorType:   'string',
            discriminatorMap:    ['dog' => JoinedDog::class, 'cat' => JoinedCat::class],
        );
    }
}

final class JoinedInheritanceHydrationTest extends TestCase
{
    private EntityHydrator $hydrator;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->registry = new MapperRegistry();
        $this->registry->register(new JoinedAnimalMapper());

        $this->hydrator = new EntityHydrator($this->registry, $connection);
    }





    public function test_joined_row_with_dog_discriminator_returns_dog_instance(): void
    {
        $row = ['id' => 1, 'name' => 'Rex', 'type' => 'dog', 'breed' => 'Labrador', 'color' => null];

        $entity = $this->hydrator->hydrate(JoinedAnimal::class, $row);

        $this->assertInstanceOf(JoinedDog::class, $entity);
    }





    public function test_joined_dog_row_sets_breed_from_flat_row(): void
    {
        $row = ['id' => 1, 'name' => 'Rex', 'type' => 'dog', 'breed' => 'Labrador', 'color' => null];


        $entity = $this->hydrator->hydrate(JoinedAnimal::class, $row);

        $this->assertInstanceOf(JoinedDog::class, $entity);
        $this->assertSame('Rex',      $entity->name);
        $this->assertSame('Labrador', $entity->breed);
    }





    public function test_joined_row_with_cat_discriminator_returns_cat_instance(): void
    {
        $row = ['id' => 2, 'name' => 'Whiskers', 'type' => 'cat', 'breed' => null, 'color' => 'black'];


        $entity = $this->hydrator->hydrate(JoinedAnimal::class, $row);

        $this->assertInstanceOf(JoinedCat::class, $entity);
        $this->assertSame('black', $entity->color);
    }





    public function test_missing_discriminator_column_returns_base_animal(): void
    {
        $row = ['id' => 3, 'name' => 'Unknown'];

        $entity = $this->hydrator->hydrate(JoinedAnimal::class, $row);

        $this->assertInstanceOf(JoinedAnimal::class, $entity);
        $this->assertSame(JoinedAnimal::class, $entity::class);
    }






    public function test_get_inheritance_join_table_returns_child_table_for_dog(): void
    {
        $factory   = new AttributeMapperFactory();
        $dogMapper = $factory->build(JoinedDog::class);



        $this->assertSame('dogs', $dogMapper->getInheritanceJoinTable());
    }





    public function test_get_inheritance_join_table_returns_null_for_parent(): void
    {
        $factory      = new AttributeMapperFactory();
        $animalMapper = $factory->build(JoinedAnimal::class);



        $this->assertNull($animalMapper->getInheritanceJoinTable());
    }





    public function test_get_inheritance_join_key_returns_id_by_default(): void
    {
        $factory   = new AttributeMapperFactory();
        $dogMapper = $factory->build(JoinedDog::class);

        $this->assertSame('id', $dogMapper->getInheritanceJoinKey());
    }
}
