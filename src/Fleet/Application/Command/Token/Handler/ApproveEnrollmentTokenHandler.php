<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token\Handler;

use Fleet\Application\Command\Token\ApproveEnrollmentToken;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

final readonly class ApproveEnrollmentTokenHandler implements CommandHandler
{
    public function __construct(private FleetAccess $access, private EnrollmentTokenRepository $tokens, private CurrentActor $actor, private ClockInterface $clock)
    {
    }

    public function __invoke(ApproveEnrollmentToken $command): void
    {
        $token = $this->tokens->find(EnrollmentTokenId::fromString($command->tokenId)) ?? throw ResourceNotFound::of('enrollment_token');
        $this->access->assert(Permission::HostApprove, $token->teamId()->toRfc4122());
        $token->approve($this->actor->actor()->userId(), $this->clock->now());
    }
}
