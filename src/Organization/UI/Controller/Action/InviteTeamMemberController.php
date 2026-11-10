<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Action;

use Organization\Application\Command\Invitation\InviteMember;
use Organization\UI\Controller\RouteCollection;
use Organization\UI\Controller\Team\TeamPage;
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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The "Invite member" dialog of the team page (overview and Members tabs). InviteMember is the judge of the
 * permission, of the rank and of the quota.
 */
#[Route(path: '/teams/{teamId}/members/invite', name: RouteCollection::TEAM_INVITE->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET', 'POST'])]
final readonly class InviteTeamMemberController
{
    public const string DIALOG = 'invite-member';

    public function __construct(private TeamPage $page, private FormFactoryInterface $forms, private DialogForm $dialog, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        // The form is served only to whom may submit it: 404 out of scope (GetTeam), 403 without the permission. The
        // command stays the judge of the submission.
        if (!$this->page->team($teamId)->can('team.members.manage')) {
            throw new AccessDeniedException();
        }

        $form = $this->forms->createNamed('invite', InviteType::class, null, ['csrf_token_id' => 'team_invite:'.$teamId]);

        return $this->dialog->handle(
            $request,
            $form,
            self::DIALOG,
            'organization.invitations.send',
            static fn (): InviteMember => new InviteMember(Uuid::v7()->toRfc4122(), $teamId, null, FormData::string($form, 'email'), FormData::string($form, 'role')),
            $this->redirect->withSuccessKey('organization.invitations.sent'),
            new RouteReference(RouteCollection::TEAM_MEMBERS->prefixed(), ['teamId' => $teamId]),
        );
    }
}
