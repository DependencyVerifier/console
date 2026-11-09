<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment\Handler;

use Organization\Application\Command\Environment\CreateEnvironment;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Shared\Application\Bus\CommandHandler;

final readonly class CreateEnvironmentHandler implements CommandHandler
{
    public function __construct(private EnvironmentAccess $access)
    {
    }

    public function __invoke(CreateEnvironment $command): void
    {
        [$project, $team] = $this->access->manage($command->projectId);
        QuotaPolicy::assertAllows($team, QuotaName::MaxEnvironmentsPerProject, $project->environmentCount());
        $project->addEnvironment(EnvironmentId::fromString($command->environmentId), $command->name, $command->production);
    }
}
