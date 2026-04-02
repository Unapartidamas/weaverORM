<?php

declare(strict_types=1);

namespace Weaver\ORM\Lifecycle;

final class EntityLifecycleInvoker
{

    private array $cache = [];

    public function invoke(object $entity, string $attributeClass): void
    {
        $class = $entity::class;
        if (!isset($this->cache[$class][$attributeClass])) {
            $this->cache[$class][$attributeClass] = $this->scan($class, $attributeClass);
        }
        foreach ($this->cache[$class][$attributeClass] as $method) {
            $entity->$method();
        }
    }

    private function scan(string $class, string $attributeClass): array
    {
        $methods = [];
        $ref = new \ReflectionClass($class);
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF) as $_attr) {
                $methods[] = $method->getName();
            }
        }
        return $methods;
    }
}
