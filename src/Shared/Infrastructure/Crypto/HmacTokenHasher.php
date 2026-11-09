<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Crypto;

use Shared\Application\Crypto\TokenHasher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\String\u;

/**
 * HMAC-SHA-256 with a server-side pepper, stored as `<kid>:<hex>`. Verification uses the kid of the stored hash, so
 * the pepper can rotate; comparison is constant-time.
 */
final readonly class HmacTokenHasher implements TokenHasher
{
    private KeyRing $peppers;

    public function __construct(#[Autowire(env: 'APP_HASH_PEPPER')] #[\SensitiveParameter] string $peppers)
    {
        $this->peppers = KeyRing::fromString($peppers, 'APP_HASH_PEPPER');
    }

    public function hash(#[\SensitiveParameter] string $token): string
    {
        return $this->peppers->currentKid.':'.hash_hmac('sha256', $token, $this->peppers->current());
    }

    public function verify(#[\SensitiveParameter] string $token, string $hash): bool
    {
        $parts = u($hash)->split(':', 2);
        if (2 !== \count($parts)) {
            return false;
        }

        $pepper = $this->peppers->get($parts[0]->toString());

        return null !== $pepper && hash_equals($parts[1]->toString(), hash_hmac('sha256', $token, $pepper));
    }
}
