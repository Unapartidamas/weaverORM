<?php
declare(strict_types=1);
namespace Weaver\ORM\Bridge\Symfony\Command;

use Weaver\ORM\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Schema\SchemaGenerator;

#[AsCommand(name: 'weaver:migrations:diff', description: 'Generate a migration file from mapper diff')]
final class MigrationsDiffCommand extends Command
{
    public function __construct(
        private readonly SchemaGenerator $schemaGenerator,
        private readonly Connection $connection,
        private readonly string $migrationsDir = '%kernel.project_dir%/migrations',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print SQL instead of creating file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sqls = $this->schemaGenerator->generateSql();

        if (empty($sqls)) {
            $io->success('No schema changes detected.');
            return self::SUCCESS;
        }

        if ($input->getOption('dump-sql')) {
            foreach ($sqls as $sql) {
                $io->writeln($sql);
            }
            return self::SUCCESS;
        }

        $timestamp = date('YmdHis');
        $className = "Version{$timestamp}";
        $dir       = rtrim($this->migrationsDir, '/');
        $filename  = "{$dir}/{$className}.php";
        $upLines   = implode("\n        ", array_map(fn($s) => "\$this->connection->executeStatement('{$s}');", $sqls));

        file_put_contents($filename, <<<PHP
        <?php
        declare(strict_types=1);
        namespace App\\Migrations;
        use Weaver\\ORM\\DBAL\\Connection;
        final class {$className}
        {
            public function up(Connection \$connection): void { {$upLines} }
            public function down(Connection \$connection): void {}
        }
        PHP);

        $io->success("Migration created: {$filename}");
        return self::SUCCESS;
    }
}
