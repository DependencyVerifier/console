<?php

declare(strict_types=1);

namespace Tests\Instance\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * An array cache that records the expiry its callback set on the last computed item.
 */
final class ExpiryRecordingCache extends ArrayAdapter
{
    private ?float $expiry = null;

    /**
     * @param array<mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return parent::get($key, function (ItemInterface $item, bool &$save) use ($callback): mixed {
            $value = $callback($item, $save);
            $expiry = new \ReflectionProperty(CacheItem::class, 'expiry')->getValue($item);
            $this->expiry = \is_int($expiry) || \is_float($expiry) ? (float) $expiry : null;

            return $value;
        }, $beta, $metadata);
    }

    public function expiresInSeconds(): float
    {
        return ($this->expiry ?? 0.0) - microtime(true);
    }
}
