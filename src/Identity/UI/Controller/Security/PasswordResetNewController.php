<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\Password\ResetPassword;
use Identity\Domain\Model\PasswordResetLinkInvalid;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\NewPasswordType;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\UI\Http\RefusalResponseTable;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Every step checks the request again by its id: pending, not expired, not consumed.
 */
#[Route(path: '/password/reset/new', name: RouteCollection::PASSWORD_RESET_NEW->value, methods: ['GET', 'POST'], priority: 1)]
final class PasswordResetNewController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'password_reset';

    public function __construct(
        private readonly PasswordResetRequestRepository $requests,
        private readonly CommandBus $commands,
        private readonly CsrfRejection $csrfRejection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $requestId = $session->get(SessionKeys::PASSWORD_RESET_REQUEST);
        $resetRequest = \is_string($requestId) && Uuid::isValid($requestId) ? $this->requests->find(Uuid::fromString($requestId)) : null;
        if (null === $resetRequest || !$resetRequest->isUsableAt($this->clock->now())) {
            return $this->invalid();
        }

        $form = $this->createForm(NewPasswordType::class, null, ['csrf_token_id' => self::CSRF_TOKEN_ID]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{password: string} $data */
            $data = $form->getData();
            try {
                $this->commands->dispatch(new ResetPassword($resetRequest->id()->toRfc4122(), $data['password']));
                $session->remove(SessionKeys::PASSWORD_RESET_REQUEST);
                $this->addFlash('success', 'identity.password_reset.done');

                return $this->redirectToRoute(RouteCollection::LOGIN->prefixed(), status: Response::HTTP_SEE_OTHER);
            } catch (AlreadyAudited $audited) {
                if ($audited->cause() instanceof PasswordResetLinkInvalid) {
                    return $this->invalid();
                }

                $form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));
                $status = RefusalResponseTable::statusOf($audited->cause());
            }
        }

        $response = $this->render('identity/security/password_reset_new.html.twig', ['form' => $form], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function invalid(): Response
    {
        $response = $this->render('identity/security/password_reset_invalid.html.twig', [], new Response(status: Response::HTTP_GONE));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
