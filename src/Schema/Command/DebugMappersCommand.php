<?php

declare(strict_types=1);

namespace Weaver\ORM\Schema\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Weaver\ORM\Mapping\MapperRegistry;

#[AsCommand(
    name: 'weaver:debug:mappers',
    description: 'List all registered Weaver ORM mappers and their tables',
)]
final class DebugMappersCommand extends Command
{
    public function __construct(
        private readonly MapperRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Registered Weaver ORM Mappers');

        $mappers = $this->registry->all();

        if ($mappers === []) {
            $io->warning('No mappers are registered.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($mappers as $entityClass => $mapper) {
            $columnCount   = count(array_filter(
                $mapper->getColumns(),
                static fn ($col): bool => !$col->isVirtual(),
            ));
            $relationCount = count($mapper->getRelations());

            $rows[] = [
                $entityClass,
                $mapper->getTableName(),
                $columnCount,
                $relationCount,
            ];
        }

        $io->table(
            ['Entity Class', 'Table', 'Columns', 'Relations'],
            $rows,
        );

        $io->note(sprintf('%d mapper(s) registered.', count($mappers)));

        return self::SUCCESS;
    }
}
