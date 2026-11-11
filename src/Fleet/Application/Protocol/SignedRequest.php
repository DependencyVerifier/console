<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

use function Symfony\Component\String\u;

/**
 * An agent request as received: method, target URI rebuilt from the request (behind a proxy, it needs
 * SYMFONY_TRUSTED_PROXIES), headers, and the exact body bytes. Never logged nor audited.
 */
final readonly class SignedRequest
{
    /** @var array<string, string> lower-case header names */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(public string $method, public string $targetUri, array $headers, #[\SensitiveParameter] public string $body)
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[u($name)->lower()->toString()] = $value;
        }

        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[u($name)->lower()->toString()] ?? null;
    }
}
