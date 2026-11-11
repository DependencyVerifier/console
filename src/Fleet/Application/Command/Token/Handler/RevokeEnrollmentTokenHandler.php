<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token\Handler;

use Fleet\Application\Command\Agent\Handler\AgentRevocation;
use Fleet\Application\Command\Token\RevokeEnrollmentToken;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RevokeEnrollmentTokenHandler implements CommandHandler
{
    public function __construct(
        private FleetAccess $access,
        private EnrollmentTokenRepository $tokens,
        private AgentRepository $agents,
        private AgentRevocation $revocation,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeEnrollmentToken $command): void
    {
        $token = $this->tokens->find(EnrollmentTokenId::fromString($command->tokenId)) ?? throw ResourceNotFound::of('enrollment_token');
        $this->access->assert(Permission::HostRevoke, $token->teamId()->toRfc4122());
        $token->revoke($this->clock->now());
        if ($command->withAgents) {
            foreach ($this->agents->ofToken($token->id()) as $agent) {
                $this->revocation->revoke($agent);
            }
        }
    }
}
