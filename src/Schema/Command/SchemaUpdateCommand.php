<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema\Command;

use Weaver\ORM\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Schema\SchemaGenerator;

#[AsCommand(
    name: 'weaver:schema:update',
    description: 'Sync the database schema to match registered mappers (dev only — use migrations in production)',
)]
final class SchemaUpdateCommand extends Command
{
    public function __construct(
        private readonly SchemaGenerator $generator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dump-sql',
            null,
            InputOption::VALUE_NONE,
            'Print the SQL statements instead of executing them',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sqls = $this->generator->generateSql();

        if ($sqls === []) {
            $io->success('Schema is already up to date — nothing to do.');

            return self::SUCCESS;
        }

        if ($input->getOption('dump-sql')) {
            $io->section('SQL to be executed');

            foreach ($sqls as $sql) {
                $io->writeln($sql);
            }

            $io->note('Use this command without --dump-sql to apply the changes.');

            return self::SUCCESS;
        }

        foreach ($sqls as $sql) {
            $this->connection->executeStatement($sql);
            $io->writeln("<info>Executed:</info> {$sql}");
        }

        $io->success('Schema updated.');

        return self::SUCCESS;
    }
}
