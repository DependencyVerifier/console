<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Audit\RequestContext;
use Shared\Application\Security\RequestContextProvider;

final readonly class FixedRequest implements RequestContextProvider
{
    public function __construct(private ?string $ip = '203.0.113.7')
    {
    }

    public function current(): RequestContext
    {
        return RequestContext::of($this->ip, 'phpunit', 'request-1');
    }
}
