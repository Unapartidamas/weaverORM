<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema\Command;

use Weaver\ORM\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Schema\SchemaGenerator;

#[AsCommand(
    name: 'weaver:schema:create',
    description: 'Create all tables from registered mappers',
)]
final class SchemaCreateCommand extends Command
{
    public function __construct(
        private readonly SchemaGenerator $generator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sqls = $this->generator->generateSql();

        if ($sqls === []) {
            $io->note('No SQL statements to execute — schema may already be up to date.');

            return self::SUCCESS;
        }

        foreach ($sqls as $sql) {
            $this->connection->executeStatement($sql);
            $io->writeln("<info>Executed:</info> {$sql}");
        }

        $io->success('Schema created.');

        return self::SUCCESS;
    }
}
