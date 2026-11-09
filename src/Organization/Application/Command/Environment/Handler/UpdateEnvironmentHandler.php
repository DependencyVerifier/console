<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment\Handler;

use Organization\Application\Command\Environment\UpdateEnvironment;
use Shared\Application\Bus\CommandHandler;

final readonly class UpdateEnvironmentHandler implements CommandHandler
{
    public function __construct(private EnvironmentAccess $access)
    {
    }

    public function __invoke(UpdateEnvironment $command): void
    {
        [$project] = $this->access->manage($command->projectId);
        $project->updateEnvironment($this->access->assertBelongs($project, $command->environmentId), $command->name, $command->production);
    }
}
