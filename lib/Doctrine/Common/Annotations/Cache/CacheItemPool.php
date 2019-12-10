<?php

namespace Doctrine\Common\Annotations\Cache;

use BadMethodCallException;
use Doctrine\Common\Cache\Cache;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @internal
 */
final class CacheItemPool implements CacheItemPoolInterface
{
    /**
     * @var Cache
     */
    private $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function getItem($key): CacheItemInterface
    {
        $value = $this->cache->fetch($this->mapKey($key));
        if (false === $value) {
            return new CacheItem($key);
        }

        return new CacheItem($key, true, $value);
    }

    public function getItems(array $keys = array()): iterable
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function hasItem($key): bool
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function clear()
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function deleteItem($key)
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function deleteItems(array $keys)
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->cache->save($this->mapKey($item->getKey()), $item->get());
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function commit(): bool
    {
        throw new BadMethodCallException('Not implemented');
    }

    private function mapKey(string $key): string
    {
        return str_replace('.', '\\', $key);
    }
}
