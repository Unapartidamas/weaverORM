<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Schema;

use Weaver\ORM\DBAL\ConnectionFactory;
use Weaver\ORM\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Exception\SchemaValidationException;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaIssue;
use Weaver\ORM\Schema\SchemaIssueType;
use Weaver\ORM\Schema\SchemaValidator;

class ValidatorWidget
{
    public ?int $id   = null;
    public string $name = '';
    public ?string $description = null;
}

class ValidatorWidgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return ValidatorWidget::class;
    }

    public function getTableName(): string
    {
        return 'validator_widgets';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',          'id',          'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('name',        'name',        'string',  nullable: false, length: 255),
            new ColumnDefinition('description', 'description', 'string',  nullable: true,  length: 512),
        ];
    }

    public function getIndexes(): array
    {
        return [];
    }
}

class ValidatorWidgetMapperWithExtraColumn extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return ValidatorWidget::class;
    }

    public function getTableName(): string
    {
        return 'validator_widgets';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',           'id',           'integer', primary: true, autoIncrement: true, nullable: false),
            new ColumnDefinition('name',         'name',         'string',  nullable: false, length: 255),
            new ColumnDefinition('description',  'description',  'string',  nullable: true,  length: 512),
            new ColumnDefinition('ghost_column', 'ghostColumn',  'string',  nullable: true,  length: 100),
        ];
    }

    public function getIndexes(): array
    {
        return [];
    }
}

function makeConnection(string ...$ddlStatements): Connection
{
    $conn = ConnectionFactory::create([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);

    foreach ($ddlStatements as $sql) {
        $conn->executeStatement($sql);
    }

    return $conn;
}

final class SchemaValidatorTest extends TestCase
{




    public function test_validate_returns_empty_when_schema_is_valid(): void
    {
        $conn = makeConnection(
            'CREATE TABLE validator_widgets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name        TEXT    NOT NULL,
                description TEXT    NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapper());

        $validator = new SchemaValidator($conn, $registry);

        $this->assertSame([], $validator->validate());
    }





    public function test_validate_returns_missing_table_issue(): void
    {

        $conn = makeConnection();

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapper());

        $validator = new SchemaValidator($conn, $registry);
        $issues    = $validator->validate();

        $this->assertCount(1, $issues);
        $this->assertSame(SchemaIssueType::MissingTable, $issues[0]->type);
        $this->assertSame('validator_widgets', $issues[0]->table);
        $this->assertNull($issues[0]->column);
    }





    public function test_validate_returns_missing_column_issue(): void
    {

        $conn = makeConnection(
            'CREATE TABLE validator_widgets (
                id   INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name TEXT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapperWithExtraColumn());

        $validator = new SchemaValidator($conn, $registry);
        $issues    = $validator->validate();


        $missingColumns = array_filter(
            $issues,
            fn(SchemaIssue $i) => $i->type === SchemaIssueType::MissingColumn,
        );

        $this->assertNotEmpty($missingColumns, 'Expected at least one MissingColumn issue.');

        $columnNames = array_map(fn(SchemaIssue $i) => $i->column, array_values($missingColumns));
        $this->assertContains('ghost_column', $columnNames);
    }





    public function test_assert_valid_throws_when_issues_exist(): void
    {
        $conn = makeConnection();

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapper());

        $validator = new SchemaValidator($conn, $registry);

        $this->expectException(SchemaValidationException::class);
        $validator->assertValid();
    }





    public function test_assert_valid_does_not_throw_when_schema_is_valid(): void
    {
        $conn = makeConnection(
            'CREATE TABLE validator_widgets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name        TEXT    NOT NULL,
                description TEXT    NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapper());

        $validator = new SchemaValidator($conn, $registry);


        $validator->assertValid();

        $this->assertTrue(true);
    }





    public function test_schema_validation_exception_returns_issues(): void
    {
        $issue1 = new SchemaIssue(SchemaIssueType::MissingTable, 'users', 'Table `users` does not exist');
        $issue2 = new SchemaIssue(SchemaIssueType::MissingColumn, 'orders', 'Column `total` missing', 'total');

        $exception = SchemaValidationException::fromIssues([$issue1, $issue2]);

        $this->assertSame([$issue1, $issue2], $exception->getIssues());
        $this->assertStringContainsString('Schema validation failed', $exception->getMessage());
        $this->assertStringContainsString('missing_table', $exception->getMessage());
        $this->assertStringContainsString('missing_column', $exception->getMessage());
    }





    public function test_validate_empty_registry_returns_no_issues(): void
    {
        $conn     = makeConnection();
        $registry = new MapperRegistry();
        $validator = new SchemaValidator($conn, $registry);

        $this->assertSame([], $validator->validate());
    }





    public function test_missing_column_issue_has_correct_metadata(): void
    {
        $conn = makeConnection(
            'CREATE TABLE validator_widgets (
                id   INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name TEXT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new ValidatorWidgetMapperWithExtraColumn());

        $validator = new SchemaValidator($conn, $registry);
        $issues    = $validator->validate();

        $ghostIssue = null;
        foreach ($issues as $issue) {
            if ($issue->column === 'ghost_column') {
                $ghostIssue = $issue;
                break;
            }
        }

        $this->assertNotNull($ghostIssue, 'Expected a MissingColumn issue for ghost_column.');
        $this->assertSame(SchemaIssueType::MissingColumn, $ghostIssue->type);
        $this->assertSame('validator_widgets', $ghostIssue->table);
        $this->assertSame('ghost_column', $ghostIssue->column);
    }
}
