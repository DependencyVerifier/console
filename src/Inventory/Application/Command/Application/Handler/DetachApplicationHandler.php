<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application\Handler;

use Inventory\Application\Command\Application\DetachApplication;
use Shared\Application\Bus\CommandHandler;

/**
 * Detaching needs the permission on the project of the current environment. An application not attached stays as it
 * is: marking it as decided by a human would need no permission at all.
 */
final readonly class DetachApplicationHandler implements CommandHandler
{
    public function __construct(private ApplicationAttachment $attachment)
    {
    }

    public function __invoke(DetachApplication $command): void
    {
        [$app, $scope] = $this->attachment->visible($command->appId);
        $current = $scope->environments[$app->environmentId()?->toRfc4122() ?? ''] ?? null;
        if (null === $current) {
            return;
        }

        $this->attachment->assertMayAssign($app, $current->projectId);
        $app->unassign();
    }
}
