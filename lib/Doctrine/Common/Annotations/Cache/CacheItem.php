<?php

namespace Doctrine\Common\Annotations\Cache;

use BadMethodCallException;
use Psr\Cache\CacheItemInterface;

/**
 * @internal
 */
final class CacheItem implements CacheItemInterface
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var bool
     */
    private $isHit;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @param mixed $value
     */
    public function __construct(string $key, bool $isHit = false, $value = null)
    {
        $this->key = $key;
        $this->isHit = $isHit;
        $this->value = $value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get()
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set($value): self
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt($expiration): self
    {
        throw new BadMethodCallException('Not implemented');
    }

    public function expiresAfter($time): self
    {
        throw new BadMethodCallException('Not implemented');
    }
}
