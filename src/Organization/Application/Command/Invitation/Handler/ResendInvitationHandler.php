<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation\Handler;

use Organization\Application\Command\Invitation\ResendInvitation;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;

/**
 * The permission is checked on the real owner first, then the limiters are consumed: an unauthorized actor cannot
 * exhaust the resend quota of the team.
 */
final readonly class ResendInvitationHandler implements CommandHandler
{
    public function __construct(
        private InvitationAccess $access,
        private AttemptThrottle $throttle,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResendInvitation $command): void
    {
        $invitation = $this->access->manage($command->invitationId);
        foreach ([[ThrottleName::InvitationResend, ThrottleSubject::forInvitation($command->invitationId)], [ThrottleName::InvitationResendTeam, ThrottleSubject::forTeam($invitation->target()->teamId()->toString())]] as [$name, $subject]) {
            $decision = $this->throttle->consume($name, $subject);
            if (!$decision->accepted) {
                throw new Throttled($decision->retryAfter);
            }
        }

        $token = $this->tokens->urlToken();
        $invitation->reissue($token, $this->hasher->hash($token), $this->clock->now());
    }
}
