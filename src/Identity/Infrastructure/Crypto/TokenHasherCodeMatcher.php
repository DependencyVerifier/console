<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Crypto;

use Identity\Domain\Security\CodeHashMatcher;
use Shared\Application\Crypto\TokenHasher;

use function Symfony\Component\String\u;

/**
 * Codes are typed by hand: surrounding spaces and case do not matter.
 */
final readonly class TokenHasherCodeMatcher implements CodeHashMatcher
{
    public function __construct(private TokenHasher $hasher)
    {
    }

    public function matches(#[\SensitiveParameter] string $code, string $hash): bool
    {
        return $this->hasher->verify(u($code)->trim()->lower()->toString(), $hash);
    }
}
