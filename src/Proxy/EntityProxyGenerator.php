<?php

declare(strict_types=1);

namespace Weaver\ORM\Proxy;

use Weaver\ORM\Mapping\AbstractEntityMapper;
use Weaver\ORM\Mapping\RelationDefinition;
use Weaver\ORM\Mapping\RelationType;

final class EntityProxyGenerator
{

    public function generate(
        string $entityClass,
        AbstractEntityMapper $mapper,
        string $outputPath,
    ): string {
        $ref       = new \ReflectionClass($entityClass);
        $shortName = $ref->getShortName();
        $namespace = $ref->getNamespaceName();
        $proxyName = $shortName . '__WeaverProxy';
        $fqcn      = $namespace !== '' ? $namespace . '\\' . $proxyName : $proxyName;

        $hooks = $this->buildPropertyHooks($mapper->getRelations());

        $hooksCode = implode("\n\n    ", $hooks);
        if ($hooksCode !== '') {
            $hooksCode = "\n    " . $hooksCode . "\n";
        }

        $nsLine = $namespace !== '' ? "namespace {$namespace};\n\n" : '';

        $parentFqcn = '\\' . ltrim($entityClass, '\\');

        $code = <<<PHP
        <?php
        declare(strict_types=1);

        {$nsLine}final class {$proxyName} extends {$parentFqcn}
        {
            /** @internal injected by EntityHydrator after hydration */
            public ?\Closure \$__weaverLoader = null;
            /** @internal lazy-load cache keyed by relation name */
            private array \$__weaverCache = [];
        {$hooksCode}}
        PHP;

        $code = $this->dedent($code);

        file_put_contents($outputPath, $code);

        return $fqcn;
    }

    private function buildPropertyHooks(array $relations): array
    {
        $hooks = [];

        foreach ($relations as $relation) {
            $propName = $relation->getProperty();
            $type     = $this->resolvePhpType($relation);
            $setType  = $type;

            $hooks[] = <<<PHP
            public {$type} \${$propName} {
                    get {
                        if (!array_key_exists('{$propName}', \$this->__weaverCache)) {
                            \$this->__weaverCache['{$propName}'] = (\$this->__weaverLoader)('{$propName}', \$this);
                        }
                        return \$this->__weaverCache['{$propName}'];
                    }
                    set({$setType} \$value) {
                        \$this->__weaverCache['{$propName}'] = \$value;
                    }
                }
            PHP;
        }

        return $hooks;
    }

    private function resolvePhpType(RelationDefinition $relation): string
    {

        return 'mixed';
    }

    private function dedent(string $code): string
    {
        $lines  = explode("\n", $code);
        $indent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $spaces = strlen($line) - strlen(ltrim($line, ' '));
            $indent = min($indent, $spaces);
        }

        if ($indent === PHP_INT_MAX || $indent === 0) {
            return $code;
        }

        $result = [];
        foreach ($lines as $line) {
            $result[] = substr($line, $indent);
        }

        return implode("\n", $result);
    }
}
