<?php

declare(strict_types=1);

namespace Weaver\ORM\Cache;

use Psr\SimpleCache\CacheInterface;

final class QueryResultCache
{
    private const PREFIX = 'weaver_qrc_';
    private const TRACKING_KEY = 'weaver_qrc___all_keys__';

    public function __construct(
        private readonly CacheInterface $adapter,
    ) {}

    public function get(string $cacheKey): ?array
    {
        $key = self::PREFIX . $cacheKey;
        $data = $this->adapter->get($key);

        return is_array($data) ? $data : null;
    }

    public function put(string $cacheKey, array $rows, int $ttl = 60): void
    {
        $key = self::PREFIX . $cacheKey;
        $this->adapter->set($key, $rows, $ttl);

        $tracked = $this->adapter->get(self::TRACKING_KEY);
        if (!is_array($tracked)) {
            $tracked = [];
        }
        $tracked[$key] = true;
        $this->adapter->set(self::TRACKING_KEY, $tracked);
    }

    public function invalidate(string $cacheKey): void
    {
        $key = self::PREFIX . $cacheKey;
        $this->adapter->delete($key);
    }

    public function invalidateAll(): void
    {
        $tracked = $this->adapter->get(self::TRACKING_KEY);

        if (is_array($tracked)) {
            foreach (array_keys($tracked) as $key) {
                $this->adapter->delete($key);
            }
        }

        $this->adapter->delete(self::TRACKING_KEY);
    }
}
