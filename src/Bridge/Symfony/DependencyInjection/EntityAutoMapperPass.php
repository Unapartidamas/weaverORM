<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Weaver\ORM\Mapping\Attribute\Entity;
use Weaver\ORM\Mapping\AttributeEntityMapper;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Mapping\MapperRegistry;

final class EntityAutoMapperPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(MapperRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(MapperRegistry::class);
        $projectDir = $container->getParameter('kernel.project_dir');
        $entityDir = $projectDir . '/src/Entity';

        if (!is_dir($entityDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Derive FQCN from path relative to src/
            $relative = str_replace($projectDir . '/src/', '', $file->getPathname());
            $className = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (!class_exists($className, true)) {
                continue;
            }

            $ref = new \ReflectionClass($className);

            if ($ref->isAbstract() || $ref->isInterface() || empty($ref->getAttributes(Entity::class))) {
                continue;
            }

            $shortName = strtolower($ref->getShortName());
            $mapperId = 'weaver.mapper.auto.' . $shortName;

            $mapperDef = new Definition(AttributeEntityMapper::class);
            $mapperDef->setFactory([new Definition(AttributeMapperFactory::class), 'build']);
            $mapperDef->setArguments([$className]);
            $mapperDef->setPublic(true);
            $container->setDefinition($mapperId, $mapperDef);

            $registry->addMethodCall('register', [new Reference($mapperId)]);
        }
    }
}
