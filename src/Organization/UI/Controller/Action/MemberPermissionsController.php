<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Member\ChangeExtraPermissions;
use Organization\Application\Query\Member\GetMemberPermissions;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\ExtraPermissionsType;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The "Permissions" dialog of a member row, on the team (Members tab) or on a project. GetMemberPermissions is the
 * judge of what the dialog shows (404 / 403); ChangeExtraPermissions applies every change at once.
 */
#[Route(path: '/teams/{teamId}/members/{userId}/permissions', name: RouteCollection::TEAM_MEMBER_EXTRA->value, requirements: ['teamId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['GET', 'POST'])]
#[Route(path: '/teams/{teamId}/projects/{projectId}/members/{userId}/permissions', name: RouteCollection::PROJECT_MEMBER_EXTRA->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID, 'userId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class MemberPermissionsController
{
    public const string DIALOG_PREFIX = 'member-permissions-';

    public function __construct(private QueryBus $queries, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $userId, ?string $projectId = null): Response
    {
        $member = $this->queries->ask(new GetMemberPermissions($teamId, $projectId, $userId));
        $form = $this->forms->createNamed('extra', ExtraPermissionsType::class, ['permissions' => $member->granted()], [
            'permission_choices' => $member->choices,
            'csrf_token_id' => \sprintf('member_extra:%s:%s', $projectId ?? $teamId, $userId),
        ]);
        $page = null === $projectId
            ? new RouteReference(RouteCollection::TEAM_MEMBERS->prefixed(), ['teamId' => $teamId])
            : new RouteReference(RouteCollection::PROJECT->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG_PREFIX.$userId,
            'action.save',
            static function () use ($form, $teamId, $projectId, $userId): ChangeExtraPermissions {
                /** @var list<string> $permissions */
                $permissions = array_values((array) $form->get('permissions')->getData());

                return new ChangeExtraPermissions($teamId, $projectId, $userId, $permissions);
            },
            $this->redirect->withSuccessKey('organization.permissions.saved'),
            $page,
        );
    }
}
