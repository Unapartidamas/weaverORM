<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Schema;

use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\IndexDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaGenerator;

class Product
{
    public ?int $id       = null;
    public string $sku    = '';
    public string $name   = '';
    public float $price   = 0.0;
}

class ProductMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Product::class;
    }

    public function getTableName(): string
    {
        return 'products';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true,  autoIncrement: true, nullable: false),
            new ColumnDefinition('sku',   'sku',   'string',  primary: false, autoIncrement: false, nullable: false, length: 128),
            new ColumnDefinition('name',  'name',  'string',  primary: false, autoIncrement: false, nullable: false, length: 255),
            new ColumnDefinition('price', 'price', 'float',   primary: false, autoIncrement: false, nullable: true),
        ];
    }

    public function getIndexes(): array
    {
        return [
            new IndexDefinition(['sku'], unique: true, name: 'uniq_products_sku'),
        ];
    }
}

class Category
{
    public ?int $id    = null;
    public string $slug = '';
}

class CategoryMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return Category::class;
    }

    public function getTableName(): string
    {
        return 'categories';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',   'id',   'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('slug', 'slug', 'string',  nullable: false, length: 64),
        ];
    }
}

final class SchemaGeneratorTest extends TestCase
{
    private MapperRegistry $registry;
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->registry = new MapperRegistry();
        $this->registry->register(new ProductMapper());

        $this->generator = new SchemaGenerator(
            $this->registry,
            $connection->getDatabasePlatform(),
        );
    }



    public function test_generates_schema_with_correct_table_name(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertStringContainsString(
            'products',
            $combined,
            'Generated schema should contain a "products" table.',
        );
    }

    public function test_generates_primary_key_column(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertStringContainsStringIgnoringCase(
            'PRIMARY',
            $combined,
            'Table should have a primary key.',
        );
    }

    public function test_generates_all_columns(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        foreach (['id', 'sku', 'name', 'price'] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $combined,
                "Column \"$expected\" should be present in the products table.",
            );
        }
    }

    public function test_nullable_column_is_not_required(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertMatchesRegularExpression(
            '/price.*(?!NOT NULL)/i',
            $combined,
            '"price" is declared nullable so it should not have NOT NULL constraint.',
        );
    }

    public function test_unique_index_is_generated(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertStringContainsStringIgnoringCase(
            'UNIQUE INDEX',
            $combined,
            'The unique index "uniq_products_sku" should exist on the products table.',
        );

        $this->assertStringContainsString(
            'uniq_products_sku',
            $combined,
            '"uniq_products_sku" index name should appear in the SQL.',
        );
    }

    public function test_creates_schema_in_real_sqlite(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $registry = new MapperRegistry();
        $registry->register(new ProductMapper());

        $generator = new SchemaGenerator($registry, $connection->getDatabasePlatform());

        foreach ($generator->generateSql() as $sql) {
            $connection->executeStatement($sql);
        }

        $affected = $connection->executeStatement(
            "INSERT INTO products (sku, name, price) VALUES (?, ?, ?)",
            ['SKU-001', 'Widget', 9.99],
        );

        $this->assertSame(1, $affected);
    }

    public function test_generated_sql_contains_create_table(): void
    {
        $sqls = $this->generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertStringContainsStringIgnoringCase(
            'CREATE TABLE',
            $combined,
            'generateSql() output should contain a CREATE TABLE statement.',
        );
    }

    public function test_schema_for_multiple_mappers(): void
    {
        $connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $registry = new MapperRegistry();
        $registry->register(new ProductMapper());
        $registry->register(new CategoryMapper());

        $generator = new SchemaGenerator($registry, $connection->getDatabasePlatform());
        $sqls = $generator->generateSql();
        $combined = implode(' ', $sqls);

        $this->assertStringContainsString('products', $combined, 'Schema should have "products" table.');
        $this->assertStringContainsString('categories', $combined, 'Schema should have "categories" table.');
    }
}
