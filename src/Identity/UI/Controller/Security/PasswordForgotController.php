<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\Password\RequestPasswordReset;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\EmailRequestType;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Always the same answer, whether the account exists, is throttled or not (anti-enumeration); the email leaves after
 * the response.
 */
#[Route(path: '/password/forgot', name: RouteCollection::PASSWORD_FORGOT->value, methods: ['GET', 'POST'])]
final class PasswordForgotController extends AbstractController
{
    public function __construct(private readonly CommandBus $commands, private readonly CsrfRejection $csrfRejection)
    {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(EmailRequestType::class);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();
            try {
                $this->commands->dispatch(new RequestPasswordReset(Uuid::v7()->toRfc4122(), $data['email']));
            } catch (AlreadyAudited) {
                // Throttled or invalid: audited once, the answer stays the same.
            }

            return $this->noStore($this->render('identity/security/password_forgot_sent.html.twig'));
        }

        return $this->noStore($this->render('identity/security/password_forgot.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200)));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
