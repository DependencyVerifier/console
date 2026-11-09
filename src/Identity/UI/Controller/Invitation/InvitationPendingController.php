<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Invitation;

use Identity\Application\Command\Account\ActivateInvitedAccount;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\NewPasswordType;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\UI\Action\ActionButtonType;
use Shared\UI\Action\FormData;
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

use function Symfony\Component\String\u;

/**
 * Orchestrator of the invitation flow (the invitation id is in the session, rechecked at every step):
 * - signed in with the invited email: "Accept" (POST /invitations/accept);
 * - otherwise: choose a password (ActivateInvitedAccount), or sign in when the account exists; the login comes back
 *   here (target path) and the email second factor applies as usual.
 */
#[Route(path: '/invitations/pending', name: RouteCollection::INVITATION_PENDING->value, methods: ['GET', 'POST'], priority: 1)]
final class InvitationPendingController extends AbstractController
{
    public const string CSRF_ACCEPT = 'invitation_accept';
    public const string CSRF_ACTIVATE = 'invitation_activate';

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
        $session = $request->getSession();
        $invitation = $this->invitation($session->get(SessionKeys::INVITATION));
        if (null === $invitation) {
            return $this->pages->invalid();
        }

        $session->set('_security.main.target_path', $this->generateUrl(RouteCollection::INVITATION_PENDING->prefixed()));
        if ($this->isGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS')) {
            // A public page: scheb lets a second factor in progress through, so finish it first, then come back here.
            return $this->redirectToRoute('2fa_login');
        }

        $user = $this->getUser();
        if ($user instanceof SecurityUser && $this->isGranted('IS_AUTHENTICATED_FULLY')) {
            $session->remove('_security.main.target_path');

            return $this->page($invitation, [
                'signed_in_as_invitee' => u($user->email)->equalsTo($invitation->email),
                'accept_form' => $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => self::CSRF_ACCEPT])->createView(),
            ]);
        }

        $form = $this->forms->createNamed('activate', NewPasswordType::class, null, ['csrf_token_id' => self::CSRF_ACTIVATE]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new ActivateInvitedAccount(Uuid::v7()->toRfc4122(), $invitation->invitationId, FormData::string($form, 'password')));
                $this->addFlash('success', 'identity.invitation.account_created');

                return $this->redirectToRoute(RouteCollection::LOGIN->prefixed(), status: Response::HTTP_SEE_OTHER);
            } catch (AlreadyAudited $audited) {
                $form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));
                $status = RefusalResponseTable::statusOf($audited->cause());
            }
        }

        return $this->page($invitation, ['activate_form' => $form->createView()], $status);
    }

    private function invitation(mixed $id): ?PendingInvitation
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        try {
            return $this->queries->ask(FindPendingInvitation::byId($id));
        } catch (AlreadyAudited) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(PendingInvitation $invitation, array $context, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('identity/invitation/pending.html.twig', ['invitation' => $invitation, ...$context], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
