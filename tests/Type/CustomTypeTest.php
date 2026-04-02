<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Type;

use Weaver\ORM\DBAL\ConnectionFactory;
use Weaver\ORM\DBAL\Platform;
use Weaver\ORM\DBAL\Type\Type;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Event\LifecycleEventDispatcher;
use Weaver\ORM\Hydration\EntityHydrator;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Persistence\InsertOrderResolver;
use Weaver\ORM\Persistence\UnitOfWork;
use Weaver\ORM\Type\MoneyType;
use Weaver\ORM\Type\TypeRegistry;
use Weaver\ORM\Type\UlidType;
use Weaver\ORM\Type\WeaverType;

final class ColorType extends WeaverType
{
    public function getName(): string
    {
        return 'test_color_hex';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'CHAR(7)';
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        return $value === null ? null : (string) $value;
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        return $value === null ? null : (string) $value;
    }
}

class ProductEntity
{
    public ?int $id    = null;
    public string $ulid = '';
    public int $price  = 0;
}

class ProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return ProductEntity::class;
    }

    public function getTableName(): string
    {
        return 'products';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer',     primary: true, autoIncrement: true),
            new ColumnDefinition('ulid',  'ulid',  'ulid',        length: 26),
            new ColumnDefinition('price', 'price', 'money_cents'),
        ];
    }
}

final class CustomTypeTest extends TestCase
{
    protected function setUp(): void
    {


        TypeRegistry::registerOne('ulid', UlidType::class);
        TypeRegistry::registerOne('money_cents', MoneyType::class);
    }





    public function test_register_array_registers_custom_type(): void
    {

        $typeName = 'test_custom_register_' . uniqid();



        $typeClass = new class extends WeaverType {
            public static string $registeredName = '';

            public function getName(): string
            {
                return self::$registeredName;
            }

            public function convertToPHPValue(mixed $value, Platform $platform): mixed
            {
                return $value;
            }

            public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
            {
                return $value;
            }
        };

        $typeClassName = $typeClass::class;
        $typeClass::$registeredName = $typeName;


        TypeRegistry::register([$typeName => $typeClassName]);

        self::assertTrue(
            Type::hasType($typeName),
            "TypeRegistry::register() must make the type resolvable via DBAL Type::hasType()",
        );
    }





    public function test_registering_same_type_twice_is_idempotent(): void
    {

        TypeRegistry::registerOne('ulid', UlidType::class);
        TypeRegistry::registerOne('ulid', UlidType::class);


        TypeRegistry::register(['ulid' => UlidType::class]);

        self::assertTrue(Type::hasType('ulid'));
    }





    public function test_dbal_resolves_registered_types_by_name(): void
    {
        self::assertTrue(Type::hasType('ulid'), "'ulid' must be registered");
        self::assertTrue(Type::hasType('money_cents'), "'money_cents' must be registered");

        $ulidType  = Type::getType('ulid');
        $moneyType = Type::getType('money_cents');

        self::assertInstanceOf(UlidType::class, $ulidType);
        self::assertInstanceOf(MoneyType::class, $moneyType);
    }





    public function test_ulid_column_persists_and_hydrates_end_to_end(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement(
            'CREATE TABLE products (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                ulid  CHAR(26) NOT NULL DEFAULT \'\',
                price INTEGER   NOT NULL DEFAULT 0
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ProductMapper());

        $hydrator   = new EntityHydrator($registry, $connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($connection, $registry, $hydrator, $dispatcher, $resolver);

        $ulidValue = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $product        = new ProductEntity();
        $product->ulid  = $ulidValue;
        $product->price = 100;

        $uow->add($product);
        $uow->push();

        self::assertNotNull($product->id);


        $row = $connection->fetchAssociative(
            'SELECT * FROM products WHERE id = ?',
            [$product->id],
        );

        self::assertNotFalse($row);


        $platform  = $connection->getDatabasePlatform();
        $ulidType  = Type::getType('ulid');
        $phpValue  = $ulidType->convertToPHPValue($row['ulid'], $platform);

        self::assertSame($ulidValue, $phpValue, "ULID must round-trip through CHAR(26) storage unchanged");
        self::assertSame($ulidValue, $row['ulid'], "Raw DB value must match the stored ULID string");
    }





    public function test_money_cents_column_round_trips_correctly(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement(
            'CREATE TABLE products (
                id    INTEGER PRIMARY KEY AUTOINCREMENT,
                ulid  CHAR(26) NOT NULL DEFAULT \'\',
                price INTEGER   NOT NULL DEFAULT 0
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ProductMapper());

        $hydrator   = new EntityHydrator($registry, $connection);
        $dispatcher = new LifecycleEventDispatcher();
        $resolver   = new InsertOrderResolver($registry);
        $uow        = new UnitOfWork($connection, $registry, $hydrator, $dispatcher, $resolver);

        $priceCents = 4999;

        $product        = new ProductEntity();
        $product->ulid  = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $product->price = $priceCents;

        $uow->add($product);
        $uow->push();

        self::assertNotNull($product->id);

        $row = $connection->fetchAssociative(
            'SELECT * FROM products WHERE id = ?',
            [$product->id],
        );

        self::assertNotFalse($row);


        $platform  = $connection->getDatabasePlatform();
        $moneyType = Type::getType('money_cents');
        $phpValue  = $moneyType->convertToPHPValue($row['price'], $platform);

        self::assertSame($priceCents, $phpValue, "money_cents must round-trip as an integer");
        self::assertIsInt($phpValue, "convertToPHPValue() must return int");
    }





    public function test_ulid_type_sql_declaration_is_char26(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $platform  = $connection->getDatabasePlatform();
        $ulidType  = Type::getType('ulid');

        self::assertSame('CHAR(26)', $ulidType->getSQLDeclaration([], $platform));
    }

    public function test_money_type_sql_declaration_is_integer(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $platform  = $connection->getDatabasePlatform();
        $moneyType = Type::getType('money_cents');

        self::assertSame('INTEGER', $moneyType->getSQLDeclaration([], $platform));
    }

    public function test_ulid_type_converts_null_to_null(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $platform = $connection->getDatabasePlatform();
        $ulidType = Type::getType('ulid');

        self::assertNull($ulidType->convertToPHPValue(null, $platform));
        self::assertNull($ulidType->convertToDatabaseValue(null, $platform));
    }

    public function test_money_type_converts_null_to_null(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $platform  = $connection->getDatabasePlatform();
        $moneyType = Type::getType('money_cents');

        self::assertNull($moneyType->convertToPHPValue(null, $platform));
        self::assertNull($moneyType->convertToDatabaseValue(null, $platform));
    }
}
