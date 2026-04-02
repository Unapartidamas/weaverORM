<?php

declare(strict_types=1);

namespace Weaver\ORM\Command;

use Weaver\ORM\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Schema\SchemaGenerator;

#[AsCommand(name: 'weaver:schema:sql', description: 'Generate CREATE TABLE SQL for all mapped entities')]
final class SchemaGenerateSqlCommand extends Command
{
    public function __construct(
        private readonly SchemaGenerator $schemaGenerator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        $sqls = $this->schemaGenerator->generateSql();

        if ($sqls === []) {
            $io->warning('No mappers registered — nothing to generate.');
            return self::SUCCESS;
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode($sqls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($sqls as $sql) {
                $output->writeln($sql . ';');
            }
        }

        return self::SUCCESS;
    }
}
