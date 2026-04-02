<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Proxy\EntityProxyGenerator;
use Weaver\ORM\Proxy\EntityProxyLoader;
use Weaver\ORM\Tests\Fixture\Entity\Article;

final class LazyLoadingIntegrationTest extends TestCase
{
    private const CACHE_DIR = '/tmp/weaver-proxies-test';

    private AttributeMapperFactory $factory;
    private EntityProxyGenerator $generator;
    private EntityProxyLoader $loader;

    protected function setUp(): void
    {
        $this->factory   = new AttributeMapperFactory();
        $this->generator = new EntityProxyGenerator();
        $this->loader    = new EntityProxyLoader(
            self::CACHE_DIR,
            $this->factory,
            $this->generator,
        );
    }

    public function test_proxy_class_is_generated_and_loadable(): void
    {
        $proxyClass = $this->loader->getProxyClass(Article::class);

        self::assertStringContainsString('Article__WeaverProxy', $proxyClass);
        self::assertTrue(class_exists($proxyClass));
    }

    public function test_proxy_extends_original_entity(): void
    {
        $proxyClass = $this->loader->getProxyClass(Article::class);
        $proxy      = new $proxyClass();

        self::assertInstanceOf(Article::class, $proxy);
    }

    public function test_accessing_relation_calls_loader_once(): void
    {
        $proxyClass  = $this->loader->getProxyClass(Article::class);
        $proxy       = new $proxyClass();
        $callCount   = 0;
        $returnValue = new \Weaver\ORM\Collection\EntityCollection([]);

        $proxy->__weaverLoader = function (string $relation, object $entity) use (&$callCount, $returnValue): mixed {
            $callCount++;
            return $returnValue;
        };


        $result1 = $proxy->comments;
        self::assertSame(1, $callCount, 'Loader should be called exactly once on first access');
        self::assertSame($returnValue, $result1);
    }

    public function test_accessing_relation_twice_calls_loader_only_once(): void
    {
        $proxyClass  = $this->loader->getProxyClass(Article::class);
        $proxy       = new $proxyClass();
        $callCount   = 0;
        $returnValue = new \Weaver\ORM\Collection\EntityCollection([]);

        $proxy->__weaverLoader = function (string $relation, object $entity) use (&$callCount, $returnValue): mixed {
            $callCount++;
            return $returnValue;
        };


        $result1 = $proxy->comments;

        $result2 = $proxy->comments;

        self::assertSame(1, $callCount, 'Loader should be called only once; second access must use cache');
        self::assertSame($result1, $result2, 'Both accesses must return the same cached instance');
    }

    public function test_setter_populates_cache_without_loader_call(): void
    {
        $proxyClass  = $this->loader->getProxyClass(Article::class);
        $proxy       = new $proxyClass();
        $callCount   = 0;
        $collection  = new \Weaver\ORM\Collection\EntityCollection([]);

        $proxy->__weaverLoader = function (string $relation, object $entity) use (&$callCount): mixed {
            $callCount++;
            return null;
        };


        $proxy->comments = $collection;


        $result = $proxy->comments;

        self::assertSame(0, $callCount, 'Loader should not be called when value was set directly');
        self::assertSame($collection, $result);
    }
}
