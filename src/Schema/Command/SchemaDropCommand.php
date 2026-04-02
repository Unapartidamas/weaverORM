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
use Weaver\ORM\Mapping\MapperRegistry;

#[AsCommand(
    name: 'weaver:schema:drop',
    description: 'Drop all tables managed by registered mappers',
)]
final class SchemaDropCommand extends Command
{
    public function __construct(
        private readonly MapperRegistry $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $confirmed = $io->confirm(
                'This will drop ALL tables managed by Weaver ORM. Are you sure?',
                false,
            );

            if (!$confirmed) {
                $io->note('Aborted.');

                return self::SUCCESS;
            }
        }

        $tables = [];
        foreach ($this->registry->all() as $mapper) {
            $tables[] = $mapper->getTableName();
        }

        if ($tables === []) {
            $io->note('No tables to drop.');

            return self::SUCCESS;
        }

        foreach (array_reverse($tables) as $table) {
            $sql = 'DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($table);
            $this->connection->executeStatement($sql);
            $io->writeln("<info>Executed:</info> {$sql}");
        }

        $io->success('Schema dropped.');

        return self::SUCCESS;
    }
}
