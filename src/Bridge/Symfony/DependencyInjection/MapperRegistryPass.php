<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Weaver\ORM\Mapping\MapperRegistry;

final class MapperRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(MapperRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(MapperRegistry::class);
        $taggedMappers = $container->findTaggedServiceIds('weaver.mapper');

        foreach (array_keys($taggedMappers) as $serviceId) {
            $registry->addMethodCall('register', [new Reference($serviceId)]);
        }
    }
}
