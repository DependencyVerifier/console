<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Invitation\InviteMember;
use Organization\UI\Controller\Project\ProjectPage;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\InviteType;
use Shared\UI\Action\DialogForm;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The "Invite member" dialog of the project page. InviteMember is the judge of the permission, of the rank and of the
 * quota; a non-member of the team also becomes TEAM_GUEST when accepting.
 */
#[Route(path: '/teams/{teamId}/projects/{projectId}/members/invite', name: RouteCollection::PROJECT_INVITE->value, requirements: ['teamId' => Requirement::UUID, 'projectId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class InviteProjectMemberController
{
    public const string DIALOG = 'invite-project-member';

    public function __construct(private ProjectPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId, string $projectId): Response
    {
        $this->page->assertCan($this->page->project($teamId, $projectId), 'project.members.manage');
        $form = $this->forms->createNamed('invite', InviteType::class, null, ['csrf_token_id' => 'project_invite:'.$projectId, 'project' => true]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'organization.invitations.send',
            static fn (): InviteMember => new InviteMember(Uuid::v7()->toRfc4122(), $teamId, $projectId, FormData::string($form, 'email'), FormData::string($form, 'role')),
            $this->redirect->withSuccessKey('organization.invitations.sent'),
            new RouteReference(RouteCollection::PROJECT_MEMBERS->prefixed(), ['teamId' => $teamId, 'projectId' => $projectId]),
        );
    }
}
