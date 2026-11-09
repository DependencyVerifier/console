<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Invitation;

use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\AcceptInvitation;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Http\RefusalResponseTable;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The signed-in invitee accepts. A refusal (quota reached since, another email…) keeps the invitation pending: the
 * account stays activated, the user can try again later.
 */
#[Route(path: '/invitations/accept', name: RouteCollection::INVITATION_ACCEPT->value, methods: ['POST'], priority: 1)]
final class InvitationAcceptController extends AbstractController
{
    public function __construct(
        private readonly InvitationPages $pages,
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CsrfRejection $csrfRejection,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => InvitationPendingController::CSRF_ACCEPT]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || null !== CsrfFailure::tokenIdOf($form)) {
            return $this->csrfRejection->reject(InvitationPendingController::CSRF_ACCEPT);
        }

        $session = $request->getSession();
        $id = $session->get(SessionKeys::INVITATION);
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return $this->pages->invalid();
        }

        try {
            $invitation = $this->queries->ask(FindPendingInvitation::byId($id));
            $this->commands->dispatch(new AcceptInvitation($id));
        } catch (AlreadyAudited $audited) {
            if (!isset($invitation)) {
                return $this->pages->invalid();
            }

            $form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));
            $response = $this->render('identity/invitation/pending.html.twig', [
                'invitation' => $invitation,
                'signed_in_as_invitee' => true,
                'accept_form' => $form->createView(),
            ], new Response(status: RefusalResponseTable::statusOf($audited->cause())));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $session->remove(SessionKeys::INVITATION);
        $this->addFlash('success', 'identity.invitation.accepted');

        return $this->redirectToRoute('organization_team', ['teamId' => $invitation->teamId], Response::HTTP_SEE_OTHER);
    }
}
