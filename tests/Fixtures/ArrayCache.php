<?php

declare(strict_types=1);

namespace Neos\Schematic\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A trivial in-memory PSR-16 cache for testing the CachingMiddleware.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    public int $hits = 0;
    public int $misses = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            $this->hits++;
            return $this->items[$key];
        }
        $this->misses++;
        return $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * @return array<string, mixed>
     */
    public function entries(): array
    {
        return $this->items;
    }
}
