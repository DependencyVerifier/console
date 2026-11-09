<?php

declare(strict_types=1);

namespace Identity\Application\Query\SecondFactor\Handler;

use Identity\Application\Query\SecondFactor\GetCodeVerificationOutcome;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\CodeVerificationOutcome;
use Identity\Domain\Repository\CodeVerificationAttemptRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Symfony\Component\Uid\Uuid;

final readonly class GetCodeVerificationOutcomeHandler implements QueryHandler
{
    public function __construct(private CodeVerificationAttemptRepository $attempts, private AccountAccess $access)
    {
    }

    public function __invoke(GetCodeVerificationOutcome $query): CodeVerificationOutcome
    {
        $attempt = $this->attempts->find(Uuid::fromString($query->attemptId));
        if (null === $attempt || !$this->access->isActor($attempt->userId())) {
            throw ResourceNotFound::of('code verification attempt');
        }

        return $attempt->outcome();
    }
}
