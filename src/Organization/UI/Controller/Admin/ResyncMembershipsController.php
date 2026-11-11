<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Member\RestoreMembershipsOfUser;
use Organization\Application\Command\Member\SuspendMembershipsOfUser;
use Organization\UI\Controller\Action\OrganizationAction;
use Organization\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * "Re-sync memberships" of a row of /admin/users: replays the cascade of the account status when its reaction failed
 * (event.handler.failed): an active account gets its memberships restored, any other one suspended. Idempotent; the
 * command checks `instance.users.manage`.
 */
#[Route(path: '/admin/users/{userId}/resync-memberships', name: RouteCollection::ADMIN_USER_RESYNC->value, requirements: ['userId' => Requirement::UUID], methods: ['POST'])]
final readonly class ResyncMembershipsController
{
    public const string ACTION = 'resync_memberships';

    public function __construct(private QueryBus $queries, private OrganizationAction $action)
    {
    }

    public function __invoke(Request $request, string $userId): Response
    {
        $account = $this->queries->ask(new FindAccounts([$userId]))[$userId] ?? throw new NotFoundHttpException();

        return $this->action->handle(
            $request,
            'action',
            self::ACTION.':'.$userId,
            static fn () => $account->active ? new RestoreMembershipsOfUser($userId) : new SuspendMembershipsOfUser($userId),
            'organization.admin.memberships_resynced',
            new RouteReference('identity_admin_users'),
        );
    }
}
