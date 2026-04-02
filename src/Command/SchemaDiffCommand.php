<?php

declare(strict_types=1);

namespace Weaver\ORM\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Schema\SchemaDiffer;

#[AsCommand(name: 'weaver:schema:diff', description: 'Show diff between entity mappings and actual database schema')]
final class SchemaDiffCommand extends Command
{
    public function __construct(
        private readonly SchemaDiffer $differ,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('fail-on-diff', null, InputOption::VALUE_NONE, 'Exit with code 1 when differences are found');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        $diff = $this->differ->diff();

        if ($format === 'json') {
            $output->writeln((string) json_encode($diff->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($diff->isEmpty()) {
                $io->success('Schema is in sync. No differences found.');
            } else {
                $io->error($diff->toText());

                if ($diff->missingTables !== []) {
                    $io->section('Missing tables');
                    $io->listing($diff->missingTables);
                }

                if ($diff->extraTables !== []) {
                    $io->section('Extra tables (in DB but not mapped)');
                    $io->listing($diff->extraTables);
                }

                if ($diff->missingColumns !== []) {
                    $io->section('Missing columns');
                    $rows = [];
                    foreach ($diff->missingColumns as $table => $columns) {
                        foreach ($columns as $column) {
                            $rows[] = [$table, $column, 'missing in DB'];
                        }
                    }
                    $io->table(['Table', 'Column', 'Issue'], $rows);
                }

                if ($diff->extraColumns !== []) {
                    $io->section('Extra columns (in DB but not in mapper)');
                    $rows = [];
                    foreach ($diff->extraColumns as $table => $columns) {
                        foreach ($columns as $column) {
                            $rows[] = [$table, $column, 'not in mapper'];
                        }
                    }
                    $io->table(['Table', 'Column', 'Issue'], $rows);
                }

                if ($diff->typeMismatches !== []) {
                    $io->section('Type mismatches');
                    $rows = [];
                    foreach ($diff->typeMismatches as $table => $columns) {
                        foreach ($columns as $column => $types) {
                            $rows[] = [$table, $column, $types['mapped'], $types['actual']];
                        }
                    }
                    $io->table(['Table', 'Column', 'Mapped type', 'Actual type'], $rows);
                }
            }
        }

        $hasDiff = !$diff->isEmpty();

        if ($hasDiff && $input->getOption('fail-on-diff')) {
            return self::FAILURE;
        }

        return $hasDiff ? self::FAILURE : self::SUCCESS;
    }
}
