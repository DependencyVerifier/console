<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Refresh\Handler;

use Exposure\Application\Command\Refresh\RequestExposureRefresh;
use Exposure\Domain\Model\TeamExposure;
use Exposure\Domain\Repository\TeamExposureRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Inventory\ListInventoriedTeams;
use Symfony\Component\Uid\Uuid;

final readonly class RequestExposureRefreshHandler implements CommandHandler
{
    public function __construct(private TeamExposureRepository $exposures, private QueryBus $queries)
    {
    }

    public function __invoke(RequestExposureRefresh $command): void
    {
        foreach (null === $command->teamId ? $this->queries->ask(new ListInventoriedTeams()) : [$command->teamId] as $teamId) {
            $team = Uuid::fromString($teamId);
            $exposure = $this->exposures->find($team);
            if (null === $exposure) {
                $exposure = TeamExposure::of($team);
                $this->exposures->add($exposure);
            }

            $exposure->requestRefresh();
        }
    }
}
