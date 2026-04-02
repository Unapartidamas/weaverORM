<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\CodeIgniter;

use Weaver\ORM\Contract\EntityMapperInterface;

final class MapperDiscovery
{
    public function discover(array $directories): array
    {
        $mapperClasses = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $classesBefore = get_declared_classes();
                require_once $file->getRealPath();
                $classesAfter = get_declared_classes();

                $newClasses = array_diff($classesAfter, $classesBefore);

                foreach ($newClasses as $class) {
                    if (is_subclass_of($class, EntityMapperInterface::class)) {
                        $mapperClasses[] = $class;
                    }
                }
            }
        }

        return $mapperClasses;
    }
}
