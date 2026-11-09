<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A disabled account or one without `account.login` (notification-only) cannot log in. Checked before the password
 * (scheb suppresses the post-authentication event of the first factor); the message is the generic one and
 * EqualTimeLoginFailure hashes a dummy password, so the response time matches a wrong password.
 */
final class AccountChecker implements UserCheckerInterface
{
    public const string REASON = 'account_cannot_log_in';

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof SecurityUser && !$user->canLogIn()) {
            throw new CustomUserMessageAccountStatusException('Invalid credentials.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
