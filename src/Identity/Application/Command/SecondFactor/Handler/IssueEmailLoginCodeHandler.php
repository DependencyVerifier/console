<?php

declare(strict_types=1);

namespace Identity\Application\Command\SecondFactor\Handler;

use Identity\Application\Command\SecondFactor\IssueEmailLoginCode;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Symfony\Component\Uid\Uuid;

/**
 * One first code at login plus three resends per 15 minutes. Refused once TOTP is active (no fallback, decision 3).
 */
final readonly class IssueEmailLoginCodeHandler implements CommandHandler
{
    public function __construct(
        private AccountAccess $access,
        private AttemptThrottle $throttle,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IssueEmailLoginCode $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $user = $this->access->lock($userId);

        $decision = $this->throttle->consume(ThrottleName::EmailCodeResend, ThrottleSubject::forAccount($command->userId));
        if (!$decision->accepted) {
            throw new Throttled($decision->retryAfter);
        }

        $code = $this->tokens->numericCode();
        $user->issueEmailLoginCode(Uuid::fromString($command->codeId), $code, $this->hasher->hash($code), $this->clock->now());
    }
}
