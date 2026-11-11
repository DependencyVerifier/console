<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application\Handler;

use Inventory\Application\Command\Application\AttachApplication;
use Inventory\Domain\Model\EnvironmentOfAnotherTeam;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The environment must be one of the team of the application's host (the database trigger checks it again), seen by
 * the actor; moving an attached application also needs the permission on its current project.
 */
final readonly class AttachApplicationHandler implements CommandHandler
{
    public function __construct(private ApplicationAttachment $attachment)
    {
    }

    public function __invoke(AttachApplication $command): void
    {
        [$app, $scope] = $this->attachment->visible($command->appId);
        $target = $scope->environments[$command->environmentId] ?? null;
        if (null === $target || !$scope->seesProject($target->projectId)) {
            throw new EnvironmentOfAnotherTeam();
        }

        $this->attachment->assertMayAssign($app, $target->projectId);
        $current = $scope->environments[$app->environmentId()?->toRfc4122() ?? ''] ?? null;
        if (null !== $current) {
            $this->attachment->assertMayAssign($app, $current->projectId);
        }

        $app->assign(Uuid::fromString($target->environmentId));
    }
}
