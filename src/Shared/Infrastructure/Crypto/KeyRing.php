<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Crypto;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * Keys of the form `kid=base64,kid2=base64` (APP_ENCRYPTION_KEYS, APP_HASH_PEPPER). The first key is the current
 * one (encrypts / hashes); all of them decrypt / verify, which allows rotation. Fail-closed: an empty, malformed or
 * short key refuses to build.
 */
final readonly class KeyRing
{
    public const int KEY_BYTES = 32;

    /**
     * @param non-empty-array<string, string> $keys
     */
    private function __construct(public string $currentKid, private array $keys)
    {
    }

    public static function fromString(#[\SensitiveParameter] string $definition, string $name): self
    {
        $keys = [];
        foreach (u($definition)->trim()->split(',') as $entry) {
            $parts = $entry->trim()->split('=', 2);
            if (2 !== \count($parts)) {
                throw new \InvalidArgumentException(\sprintf('%s must look like "kid=base64key,…".', $name));
            }

            $kid = $parts[0]->trim()->toString();
            $key = base64_decode($parts[1]->trim()->toString(), true);
            if ([] === u($kid)->match('/^[a-z0-9]{1,16}$/') || false === $key || self::KEY_BYTES !== b($key)->length()) {
                throw new \InvalidArgumentException(\sprintf('%s: every key needs a short id and %d random bytes.', $name, self::KEY_BYTES));
            }

            $keys[$kid] = $key;
        }

        if ([] === $keys) {
            throw new \InvalidArgumentException(\sprintf('%s is empty.', $name));
        }

        return new self((string) array_key_first($keys), $keys);
    }

    public function current(): string
    {
        return $this->keys[$this->currentKid];
    }

    public function get(string $kid): ?string
    {
        return $this->keys[$kid] ?? null;
    }
}
