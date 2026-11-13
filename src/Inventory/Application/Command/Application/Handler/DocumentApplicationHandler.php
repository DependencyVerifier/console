<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Application\Handler;

use Inventory\Application\Command\Application\DocumentApplication;
use Inventory\Application\Security\InventoryAccess;
use Inventory\Domain\Model\DocumentationLink;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class DocumentApplicationHandler implements CommandHandler
{
    public function __construct(private ApplicationAttachment $attachment, private InventoryAccess $access)
    {
    }

    public function __invoke(DocumentApplication $command): void
    {
        [$app, $scope] = $this->attachment->visible($command->appId);
        $projectId = $scope->environments[$app->environmentId()?->toRfc4122() ?? '']->projectId ?? null;
        if (!$this->access->mayDocument($app->teamId()->toRfc4122(), $projectId)) {
            throw PermissionDenied::for((null === $projectId ? Permission::HostEdit : Permission::ProjectAppsAssign)->value);
        }

        $app->document(array_map(static fn (array $link): DocumentationLink => new DocumentationLink($link['url'], $link['label']), $command->links));
    }
}
