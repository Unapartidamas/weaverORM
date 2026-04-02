<?php

declare(strict_types=1);

namespace Weaver\ORM\Proxy;

use Weaver\ORM\Mapping\AttributeMapperFactory;

final class EntityProxyLoader
{

    private array $proxyClasses = [];

    public function __construct(
        private readonly string $cacheDir,
        private readonly AttributeMapperFactory $factory,
        private readonly EntityProxyGenerator $generator,
    ) {}

    public function getProxyClass(string $entityClass): string
    {
        if (!isset($this->proxyClasses[$entityClass])) {
            $this->proxyClasses[$entityClass] = $this->loadOrGenerate($entityClass);
        }

        return $this->proxyClasses[$entityClass];
    }

    private function loadOrGenerate(string $entityClass): string
    {
        $ref       = new \ReflectionClass($entityClass);
        $shortName = $ref->getShortName();
        $proxyFile = $this->cacheDir . '/' . str_replace('\\', '_', $entityClass) . '__WeaverProxy.php';
        $proxyNs   = $ref->getNamespaceName();
        $proxyClass = ($proxyNs !== '' ? $proxyNs . '\\' : '') . $shortName . '__WeaverProxy';

        $entityFile = $ref->getFileName();
        if (
            $entityFile !== false
            && (!file_exists($proxyFile) || filemtime($entityFile) > filemtime($proxyFile))
        ) {
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
            $mapper = $this->factory->build($entityClass);
            $this->generator->generate($entityClass, $mapper, $proxyFile);
        }

        if (!class_exists($proxyClass)) {
            require_once $proxyFile;
        }

        return $proxyClass;
    }
}
