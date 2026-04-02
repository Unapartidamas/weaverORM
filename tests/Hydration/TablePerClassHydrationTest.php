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

#[Entity(table: 'vehicles')]
#[Inheritance('TABLE_PER_CLASS')]
#[TypeColumn(name: 'vehicle_type')]
#[TypeMap(['car' => TpcCar::class, 'truck' => TpcTruck::class])]
class TpcVehicle
{
    #[Id] public int $id = 0;
    #[Column] public string $make = '';
    #[Column] public int $year = 0;
}

#[Entity(table: 'cars')]
class TpcCar extends TpcVehicle
{
    #[Column] public int $doors = 4;
}

#[Entity(table: 'trucks')]
class TpcTruck extends TpcVehicle
{
    #[Column] public float $payload = 0.0;
}

final class TpcVehicleMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return TpcVehicle::class;
    }

    public function getTableName(): string
    {
        return 'vehicles';
    }



    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',           'id',      'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('make',          'make',    'string',  nullable: false, length: 255),
            new ColumnDefinition('year',          'year',    'integer', nullable: false),
            new ColumnDefinition('vehicle_type',  'vehicleType', 'string', nullable: true,  length: 50),

            new ColumnDefinition('doors',         'doors',   'integer', nullable: true),

            new ColumnDefinition('payload',       'payload', 'float',   nullable: true),
        ];
    }

    public function getInheritanceMapping(): ?InheritanceMapping
    {
        return new InheritanceMapping(
            type:                'TABLE_PER_CLASS',
            discriminatorColumn: 'vehicle_type',
            discriminatorType:   'string',
            discriminatorMap:    ['car' => TpcCar::class, 'truck' => TpcTruck::class],
        );
    }
}

final class TablePerClassHydrationTest extends TestCase
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
        $this->registry->register(new TpcVehicleMapper());

        $this->hydrator = new EntityHydrator($this->registry, $connection);
    }





    public function test_car_row_returns_car_instance(): void
    {
        $row = [
            'id'           => 1,
            'make'         => 'Toyota',
            'year'         => 2022,
            'vehicle_type' => 'car',
            'doors'        => 4,
        ];

        $entity = $this->hydrator->hydrate(TpcVehicle::class, $row);

        $this->assertInstanceOf(TpcCar::class, $entity);
    }





    public function test_car_row_sets_properties_correctly(): void
    {
        $row = [
            'id'           => 1,
            'make'         => 'Toyota',
            'year'         => 2022,
            'vehicle_type' => 'car',
            'doors'        => 4,
        ];


        $entity = $this->hydrator->hydrate(TpcVehicle::class, $row);

        $this->assertInstanceOf(TpcCar::class, $entity);
        $this->assertSame(1,        $entity->id);
        $this->assertSame('Toyota', $entity->make);
        $this->assertSame(2022,     $entity->year);
        $this->assertSame(4,        $entity->doors);
    }





    public function test_truck_row_returns_truck_instance(): void
    {
        $row = [
            'id'           => 2,
            'make'         => 'Ford',
            'year'         => 2021,
            'vehicle_type' => 'truck',
            'payload'      => 1500.0,
        ];

        $entity = $this->hydrator->hydrate(TpcVehicle::class, $row);

        $this->assertInstanceOf(TpcTruck::class, $entity);
    }





    public function test_truck_row_sets_properties_correctly(): void
    {
        $row = [
            'id'           => 2,
            'make'         => 'Ford',
            'year'         => 2021,
            'vehicle_type' => 'truck',
            'payload'      => 1500.0,
        ];


        $entity = $this->hydrator->hydrate(TpcVehicle::class, $row);

        $this->assertInstanceOf(TpcTruck::class, $entity);
        $this->assertSame(2,      $entity->id);
        $this->assertSame('Ford', $entity->make);
        $this->assertSame(2021,   $entity->year);
        $this->assertSame(1500.0, $entity->payload);
    }





    public function test_unknown_discriminator_falls_back_to_base_vehicle(): void
    {
        $row = ['id' => 3, 'make' => 'Unknown', 'year' => 2020, 'vehicle_type' => 'bus'];

        $entity = $this->hydrator->hydrate(TpcVehicle::class, $row);

        $this->assertInstanceOf(TpcVehicle::class, $entity);
        $this->assertSame(TpcVehicle::class, $entity::class);
    }






    public function test_get_own_columns_returns_all_columns_by_default(): void
    {
        $mapper = new TpcVehicleMapper();


        $this->assertEquals($mapper->getColumns(), $mapper->getOwnColumns());
    }






    public function test_attribute_mapper_get_own_columns_returns_declared_columns(): void
    {
        $factory    = new AttributeMapperFactory();
        $carMapper  = $factory->build(TpcCar::class);



        $ownColumns = $carMapper->getOwnColumns();

        $this->assertIsArray($ownColumns);
        $this->assertNotEmpty($ownColumns);


        $this->assertEquals($carMapper->getColumns(), $ownColumns);
    }





    public function test_table_per_class_inheritance_type_is_preserved(): void
    {
        $mapper  = new TpcVehicleMapper();
        $mapping = $mapper->getInheritanceMapping();

        $this->assertNotNull($mapping);
        $this->assertSame('TABLE_PER_CLASS', $mapping->type);
    }
}
