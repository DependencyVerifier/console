<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment\Handler;

use Organization\Application\Command\Environment\DeleteEnvironment;
use Organization\Domain\Model\EnvironmentNotEmpty;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Content\ProjectContentProbe;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class DeleteEnvironmentHandler implements CommandHandler
{
    /**
     * @param iterable<ProjectContentProbe> $probes
     */
    public function __construct(
        private EnvironmentAccess $access,
        #[AutowireIterator(ProjectContentProbe::class)]
        private iterable $probes,
    ) {
    }

    public function __invoke(DeleteEnvironment $command): void
    {
        [$project] = $this->access->manage($command->projectId);
        $id = $this->access->assertBelongs($project, $command->environmentId);
        foreach ($this->probes as $probe) {
            if ($probe->environmentHasContent($command->environmentId)) {
                throw new EnvironmentNotEmpty();
            }
        }

        $project->removeEnvironment($id);
    }
}
