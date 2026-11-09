<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password\Handler;

use Identity\Application\Command\Password\RequestPasswordReset;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\RequestContextProvider;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\Uid\Uuid;

/**
 * Anti-enumeration: an unknown, disabled or notification-only account does nothing, silently (the UI answers the
 * same). An invited account still without a password gets a link too: its setup link may have expired.
 * Beyond the limits (3 / h per email, 20 / h per IP), Throttled is raised and audited once; the UI still answers
 * the same and no email leaves.
 */
final readonly class RequestPasswordResetHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private AttemptThrottle $throttle,
        private RequestContextProvider $requestContext,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestPasswordReset $command): void
    {
        $email = EmailAddress::of($command->email);
        $byEmail = $this->throttle->consume(ThrottleName::PasswordResetEmail, ThrottleSubject::forEmail($email->value()));
        if (!$byEmail->accepted) {
            throw new Throttled($byEmail->retryAfter);
        }

        $ip = $this->requestContext->current()->ip;
        if (null !== $ip) {
            $byIp = $this->throttle->consume(ThrottleName::PasswordResetIp, ThrottleSubject::forIp($ip));
            if (!$byIp->accepted) {
                throw new Throttled($byIp->retryAfter);
            }
        }

        $user = $this->users->findByEmail($email);
        if (null === $user || !$user->maySetPassword()) {
            return;
        }

        $now = $this->clock->now();
        foreach ($this->requests->findOpenFor($user->id()) as $previous) {
            $previous->invalidate($now);
        }

        $token = $this->tokens->urlToken();
        $this->requests->add(PasswordResetRequest::issue(Uuid::fromString($command->requestId), $user->id(), $token, $this->hasher->hash($token), $now));
    }
}
