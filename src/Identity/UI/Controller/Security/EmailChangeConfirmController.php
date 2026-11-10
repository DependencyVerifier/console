<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\Administration\ConfirmEmailChange;
use Identity\Domain\Model\EmailChangeLinkInvalid;
use Identity\Domain\Repository\EmailChangeRequestRepository;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
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
 * Shows the new address and a "Confirm" button (no GET changes the state); the POST applies it, then every session
 * being revoked, the holder signs in again with the new address.
 */
#[Route(path: '/email/confirm', name: RouteCollection::EMAIL_CHANGE_CONFIRM->value, methods: ['GET', 'POST'], priority: 1)]
final class EmailChangeConfirmController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'email_change_confirm';

    public function __construct(
        private readonly EmailChangeRequestRepository $requests,
        private readonly CommandBus $commands,
        private readonly CsrfRejection $csrfRejection,
        private readonly ClockInterface $clock,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $requestId = $session->get(SessionKeys::EMAIL_CHANGE_REQUEST);
        $change = \is_string($requestId) && Uuid::isValid($requestId) ? $this->requests->find(Uuid::fromString($requestId)) : null;
        if (null === $change || !$change->isUsableAt($this->clock->now())) {
            return self::invalidPage($this->renderView('identity/security/email_change_invalid.html.twig'));
        }

        $form = $this->forms->createNamed('action', ActionButtonType::class, null, ['csrf_token_id' => self::CSRF_TOKEN_ID]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        $status = Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new ConfirmEmailChange($change->id()->toRfc4122()));
                $session->remove(SessionKeys::EMAIL_CHANGE_REQUEST);
                $this->addFlash('success', 'identity.email_change.done');

                return $this->redirectToRoute(RouteCollection::LOGIN->prefixed(), status: Response::HTTP_SEE_OTHER);
            } catch (AlreadyAudited $audited) {
                if ($audited->cause() instanceof EmailChangeLinkInvalid) {
                    return self::invalidPage($this->renderView('identity/security/email_change_invalid.html.twig'));
                }

                $form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));
                $status = RefusalResponseTable::statusOf($audited->cause());
            }
        }

        $response = $this->render('identity/security/email_change_confirm.html.twig', ['form' => $form, 'new_email' => $change->newEmail()->value()], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function invalidPage(string $html): Response
    {
        return new Response($html, Response::HTTP_GONE, ['Cache-Control' => 'no-store']);
    }
}
