<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token\Handler;

use Fleet\Application\Command\Token\CreateEnrollmentToken;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\InvalidEnrollmentTokenSettings;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * host.enroll on the team; the environment must belong to it (required for a reusable token). Under the fleet lock:
 * a full team (maxAgents) gets no new token, nor a reusable one beyond maxReusableEnrollmentTokens.
 */
final readonly class CreateEnrollmentTokenHandler implements CommandHandler
{
    public function __construct(
        private FleetAccess $access,
        private QueryBus $queries,
        private FleetQuotas $quotas,
        private EnrollmentTokenRepository $tokens,
        private TokenHasher $hasher,
        private CurrentActor $actor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateEnrollmentToken $command): void
    {
        $this->access->assert(Permission::HostEnroll, $command->teamId);
        $environment = null;
        if (null !== $command->environmentId) {
            $environment = $this->queries->ask(new FindTeamEnvironment($command->teamId, $command->environmentId)) ?? throw ResourceNotFound::of('environment');
        }

        $team = Uuid::fromString($command->teamId);
        $this->quotas->lock($team);
        $this->quotas->assertAgentSeat($team);
        $id = EnrollmentTokenId::fromString($command->tokenId);
        $hash = $this->hasher->hash($command->plainToken);
        $by = $this->actor->actor()->userId();
        $now = $this->clock->now();
        if (!$command->reusable) {
            $this->tokens->add(EnrollmentToken::single($id, $team, null === $environment ? null : Uuid::fromString($environment->environmentId), $hash, $by, $now));

            return;
        }

        if (null === $environment) {
            throw new InvalidEnrollmentTokenSettings();
        }

        $this->quotas->assertReusableTokenSeat($team);
        $this->tokens->add(EnrollmentToken::reusable($id, $team, Uuid::fromString($environment->environmentId), $hash, $by, $now, $now->modify(\sprintf('+%d days', $command->validityDays)), $command->maxUses));
    }
}
