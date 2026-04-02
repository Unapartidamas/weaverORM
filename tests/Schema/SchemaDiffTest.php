<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Schema;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Weaver\ORM\Command\SchemaDiffCommand;
use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\ColumnDefinition;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Schema\SchemaDiff;
use Weaver\ORM\Schema\SchemaDiffer;

class DiffWidget
{
    public ?int $id      = null;
    public string $name  = '';
    public string $color = '';
}

class DiffWidgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return DiffWidget::class;
    }

    public function getTableName(): string
    {
        return 'diff_widgets';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',    'id',    'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('name',  'name',  'string',  length: 255),
            new ColumnDefinition('color', 'color', 'string',  length: 50),
        ];
    }
}

class DiffGadget
{
    public ?int $id     = null;
    public string $sku  = '';
}

class DiffGadgetMapper extends AbstractEntityMapper
{
    public function getEntityClass(): string
    {
        return DiffGadget::class;
    }

    public function getTableName(): string
    {
        return 'diff_gadgets';
    }

    public function getColumns(): array
    {
        return [
            new ColumnDefinition('id',  'id',  'integer', primary: true, autoIncrement: true),
            new ColumnDefinition('sku', 'sku', 'string',  length: 64),
        ];
    }
}

function diffMakeConnection(string ...$ddlStatements): Connection
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

final class SchemaDiffTest extends TestCase
{




    public function test_diff_returns_empty_when_schema_matches(): void
    {
        $conn = diffMakeConnection(
            'CREATE TABLE diff_widgets (
                id    INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name  TEXT NOT NULL,
                color TEXT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ = new SchemaDiffer($conn, $registry);
        $diff   = $differ->diff();

        $this->assertTrue($diff->isEmpty(), 'Expected an empty diff when schema matches.');
    }





    public function test_diff_detects_missing_table(): void
    {

        $conn = diffMakeConnection();

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ = new SchemaDiffer($conn, $registry);
        $diff   = $differ->diff();

        $this->assertFalse($diff->isEmpty());
        $this->assertContains('diff_widgets', $diff->missingTables);
    }





    public function test_diff_detects_missing_column(): void
    {

        $conn = diffMakeConnection(
            'CREATE TABLE diff_widgets (
                id   INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name TEXT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ = new SchemaDiffer($conn, $registry);
        $diff   = $differ->diff();

        $this->assertFalse($diff->isEmpty());
        $this->assertArrayHasKey('diff_widgets', $diff->missingColumns);
        $this->assertContains('color', $diff->missingColumns['diff_widgets']);
    }





    public function test_diff_detects_extra_column_in_db(): void
    {

        $conn = diffMakeConnection(
            'CREATE TABLE diff_widgets (
                id     INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name   TEXT NOT NULL,
                color  TEXT NOT NULL,
                weight REAL NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ = new SchemaDiffer($conn, $registry);
        $diff   = $differ->diff();

        $this->assertFalse($diff->isEmpty());
        $this->assertArrayHasKey('diff_widgets', $diff->extraColumns);
        $this->assertContains('weight', $diff->extraColumns['diff_widgets']);
    }





    public function test_schema_diff_is_empty_true_when_no_differences(): void
    {
        $diff = new SchemaDiff([], [], [], [], []);
        $this->assertTrue($diff->isEmpty());
    }

    public function test_schema_diff_is_empty_false_when_missing_table(): void
    {
        $diff = new SchemaDiff(['some_table'], [], [], [], []);
        $this->assertFalse($diff->isEmpty());
    }

    public function test_schema_diff_is_empty_false_when_extra_table(): void
    {
        $diff = new SchemaDiff([], ['orphan_table'], [], [], []);
        $this->assertFalse($diff->isEmpty());
    }

    public function test_schema_diff_is_empty_false_when_missing_column(): void
    {
        $diff = new SchemaDiff([], [], ['users' => ['email']], [], []);
        $this->assertFalse($diff->isEmpty());
    }

    public function test_schema_diff_is_empty_false_when_extra_column(): void
    {
        $diff = new SchemaDiff([], [], [], ['users' => ['legacy_col']], []);
        $this->assertFalse($diff->isEmpty());
    }





    public function test_schema_diff_to_text_empty_diff(): void
    {
        $diff = new SchemaDiff([], [], [], [], []);
        $text = $diff->toText();

        $this->assertStringContainsString('in sync', $text);
    }

    public function test_schema_diff_to_text_with_missing_table(): void
    {
        $diff = new SchemaDiff(['orders'], [], [], [], []);
        $text = $diff->toText();

        $this->assertStringContainsString('Missing tables', $text);
        $this->assertStringContainsString('orders', $text);
    }

    public function test_schema_diff_to_text_with_extra_table(): void
    {
        $diff = new SchemaDiff([], ['legacy_log'], [], [], []);
        $text = $diff->toText();

        $this->assertStringContainsString('Extra tables', $text);
        $this->assertStringContainsString('legacy_log', $text);
    }

    public function test_schema_diff_to_text_with_missing_column(): void
    {
        $diff = new SchemaDiff([], [], ['users' => ['bio']], [], []);
        $text = $diff->toText();

        $this->assertStringContainsString('Missing columns', $text);
        $this->assertStringContainsString('users.bio', $text);
    }

    public function test_schema_diff_to_text_with_extra_column(): void
    {
        $diff = new SchemaDiff([], [], [], ['users' => ['old_field']], []);
        $text = $diff->toText();

        $this->assertStringContainsString('Extra columns', $text);
        $this->assertStringContainsString('users.old_field', $text);
    }

    public function test_schema_diff_to_array_returns_all_keys(): void
    {
        $diff  = new SchemaDiff(['t1'], ['t2'], ['t3' => ['c1']], ['t4' => ['c2']], []);
        $array = $diff->toArray();

        $this->assertArrayHasKey('missingTables', $array);
        $this->assertArrayHasKey('extraTables', $array);
        $this->assertArrayHasKey('missingColumns', $array);
        $this->assertArrayHasKey('extraColumns', $array);
        $this->assertArrayHasKey('typeMismatches', $array);

        $this->assertSame(['t1'], $array['missingTables']);
        $this->assertSame(['t2'], $array['extraTables']);
    }





    public function test_command_exits_success_when_no_diff(): void
    {
        $conn = diffMakeConnection(
            'CREATE TABLE diff_widgets (
                id    INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name  TEXT NOT NULL,
                color TEXT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ  = new SchemaDiffer($conn, $registry);
        $command = new SchemaDiffCommand($differ);

        $input  = new ArrayInput([]);
        $output = new BufferedOutput();
        $input->bind($command->getDefinition());

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode, 'Command should exit 0 when schema is in sync.');
    }

    public function test_command_exits_failure_when_diff_found(): void
    {

        $conn = diffMakeConnection();

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ  = new SchemaDiffer($conn, $registry);
        $command = new SchemaDiffCommand($differ);

        $input  = new ArrayInput([]);
        $output = new BufferedOutput();
        $input->bind($command->getDefinition());

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode, 'Command should exit 1 when schema has differences.');
    }





    public function test_diff_detects_extra_table_in_db(): void
    {
        $conn = diffMakeConnection(
            'CREATE TABLE diff_widgets (
                id    INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name  TEXT NOT NULL,
                color TEXT NOT NULL
            )',
            'CREATE TABLE orphan_table (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL
            )',
        );

        $registry = new MapperRegistry();
        $registry->register(new DiffWidgetMapper());

        $differ = new SchemaDiffer($conn, $registry);
        $diff   = $differ->diff();

        $this->assertFalse($diff->isEmpty());
        $this->assertContains('orphan_table', $diff->extraTables);
    }
}
