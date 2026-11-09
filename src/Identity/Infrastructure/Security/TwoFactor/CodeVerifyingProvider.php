<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Application\Query\SecondFactor\GetCodeVerificationOutcome;
use Identity\Application\SecondFactor\CodeVerification;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Model\InvalidSecondFactorTransition;
use Identity\Infrastructure\Security\SecondFactorThrottledException;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;

/**
 * Translates a code verification into scheb's yes / no: dispatches the command (which never throws for a wrong
 * code and audits itself), then reads the outcome. `Throttled` cancels the 2FA token.
 */
final readonly class CodeVerifyingProvider
{
    public function __construct(private CommandBus $commands, private QueryBus $queries)
    {
    }

    public function verify(CodeVerification $command): bool
    {
        try {
            $this->commands->dispatch($command);
        } catch (AlreadyAudited $audited) {
            if ($audited->cause() instanceof InvalidSecondFactorTransition) {
                return false; // The second factor changed meanwhile (reset by an admin…): already audited.
            }

            throw $audited;
        }

        $outcome = $this->queries->ask(new GetCodeVerificationOutcome($command->attemptId));
        if (CodeVerificationOutcome::Throttled === $outcome) {
            throw new SecondFactorThrottledException();
        }

        return $outcome->isAccepted();
    }
}
