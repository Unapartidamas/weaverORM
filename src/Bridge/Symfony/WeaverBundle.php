<?php

declare(strict_types=1);

namespace Weaver\ORM\Bridge\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Weaver\ORM\Bridge\Symfony\DependencyInjection\EntityAutoMapperPass;
use Weaver\ORM\Bridge\Symfony\DependencyInjection\MapperRegistryPass;
use Weaver\ORM\Bridge\Symfony\DependencyInjection\WeaverExtension;

final class WeaverBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MapperRegistryPass());
        $container->addCompilerPass(new EntityAutoMapperPass());
    }

    public function getContainerExtension(): WeaverExtension
    {
        return new WeaverExtension();
    }
}
