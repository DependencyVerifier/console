<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Symfony\Contracts\Service\ResetInterface;

/**
 * One CSP nonce per request, reset between requests (worker mode): a nonce reused across requests would let a
 * script injected in one response run in another.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(18));
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
