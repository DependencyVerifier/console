<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The second factor limiter refused: the 2FA token in progress is cancelled, the user starts again from the login.
 */
final class SecondFactorThrottledException extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'identity.login.error.throttled';
    }
}
