<?php

declare(strict_types=1);

namespace Identity\Application\Account;

use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Component\Uid\Uuid;

/**
 * An account created without password, with its single-use setup link (7 days, sent after the commit). The caller
 * holds the lock of the email and checked that it is free.
 */
final readonly class AccountInvitation
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function invite(UserId $userId, EmailAddress $email, Uuid $requestId): User
    {
        $now = $this->clock->now();
        $user = User::invite($userId, $email, $now);
        $this->users->add($user);
        $token = $this->tokens->urlToken();
        $this->requests->add(PasswordResetRequest::issueAccountSetup($requestId, $user->id(), $token, $this->hasher->hash($token), $now));

        return $user;
    }
}
