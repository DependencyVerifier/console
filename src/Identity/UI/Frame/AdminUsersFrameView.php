<?php

declare(strict_types=1);

namespace Identity\UI\Frame;

use Identity\Application\Query\User\ListUsers;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Bus\QueryBus;
use Shared\Domain\Authorization\Permission;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;
use Twig\Environment;

final readonly class AdminUsersFrameView implements FrameView
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions, private Environment $twig)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::AdminUsers;
    }

    public function isVisible(FrameContext $context): bool
    {
        return $this->permissions->isGranted(Permission::InstanceUsersManage);
    }

    public function render(FrameContext $context): string
    {
        return $this->twig->render('identity/admin/users_frame.html.twig', ['users' => $this->queries->ask(new ListUsers())]);
    }
}
