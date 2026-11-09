<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Security;

use Shared\Application\Audit\RequestContext;
use Shared\Application\Security\RequestContextProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Client IP through the configured trusted proxies only (none by default: X-Forwarded-For is ignored).
 */
final readonly class HttpRequestContextProvider implements RequestContextProvider
{
    public function __construct(private RequestStack $requests, private RequestIdHolder $requestId)
    {
    }

    public function current(): RequestContext
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return RequestContext::cli($this->requestId->current());
        }

        return RequestContext::of(
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
            $this->requestId->current(),
        );
    }
}
