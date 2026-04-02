<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Proxy;

use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\AttributeMapperFactory;
use Weaver\ORM\Proxy\EntityProxyGenerator;
use Weaver\ORM\Tests\Fixture\Entity\Article;

final class EntityProxyGeneratorTest extends TestCase
{
    private const CACHE_DIR = '/tmp/weaver-proxies-test';

    private AttributeMapperFactory $factory;
    private EntityProxyGenerator $generator;

    protected function setUp(): void
    {
        $this->factory   = new AttributeMapperFactory();
        $this->generator = new EntityProxyGenerator();

        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
    }

    private function proxyFile(string $entityClass): string
    {
        return self::CACHE_DIR . '/' . str_replace('\\', '_', $entityClass) . '__WeaverProxy.php';
    }

    public function test_generated_file_contains_proxy_class_name(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $outFile  = $this->proxyFile(Article::class);
        $proxyCls = $this->generator->generate(Article::class, $mapper, $outFile);

        self::assertStringContainsString('Article__WeaverProxy', $proxyCls);
        self::assertFileExists($outFile);

        $contents = file_get_contents($outFile);
        self::assertNotFalse($contents);
        self::assertStringContainsString('Article__WeaverProxy', $contents);
    }

    public function test_generated_file_is_valid_php(): void
    {
        $mapper  = $this->factory->build(Article::class);
        $outFile = $this->proxyFile(Article::class);
        $this->generator->generate(Article::class, $mapper, $outFile);


        $output     = shell_exec('php -l ' . escapeshellarg($outFile) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $output);
    }

    public function test_generated_proxy_extends_original(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $outFile  = $this->proxyFile(Article::class);
        $proxyCls = $this->generator->generate(Article::class, $mapper, $outFile);

        if (!class_exists($proxyCls)) {
            require_once $outFile;
        }

        $ref = new \ReflectionClass($proxyCls);
        self::assertNotNull($ref->getParentClass());
        self::assertSame(Article::class, $ref->getParentClass()->getName());
    }

    public function test_proxy_has_property_hooks_for_relations(): void
    {
        $mapper   = $this->factory->build(Article::class);
        $outFile  = $this->proxyFile(Article::class);
        $this->generator->generate(Article::class, $mapper, $outFile);

        $contents = file_get_contents($outFile);
        self::assertNotFalse($contents);


        self::assertStringContainsString('$author', $contents);
        self::assertStringContainsString('$comments', $contents);
        self::assertStringContainsString('__weaverCache', $contents);
        self::assertStringContainsString('__weaverLoader', $contents);
    }
}
