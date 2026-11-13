<?php

declare(strict_types=1);

namespace Identity\Application\Query\Passkey\Handler;

use Identity\Application\Query\Passkey\GetPasskeySignIn;
use Identity\Domain\Model\CodeKind;
use Identity\Domain\Repository\CodeVerificationAttemptRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Read by the passkey authenticator right after its command, before any session exists: an accepted sign-in of the
 * last minute only.
 */
final readonly class GetPasskeySignInHandler implements QueryHandler
{
    public function __construct(private CodeVerificationAttemptRepository $attempts, private ClockInterface $clock)
    {
    }

    public function __invoke(GetPasskeySignIn $query): ?string
    {
        $attempt = $this->attempts->find(Uuid::fromString($query->attemptId));
        $recent = null !== $attempt && $attempt->createdAt() >= $this->clock->now()->modify('-1 minute');
        $accepted = $recent && CodeKind::PasskeyLogin === $attempt->kind() && $attempt->outcome()->isAccepted();

        return $accepted ? $attempt->userId()->toString() : null;
    }
}
