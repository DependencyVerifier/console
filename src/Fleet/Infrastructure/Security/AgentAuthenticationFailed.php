<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Carries the protocol refusal (or the throttling) of a signed request through the firewall.
 */
final class AgentAuthenticationFailed extends AuthenticationException
{
    public function __construct(public readonly \Throwable $cause, public readonly ?string $claimedKeyId)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
