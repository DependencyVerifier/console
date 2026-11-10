<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Domain\Repository\EmailChangeRequestRepository;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page of the link sent to the new address: the token is exchanged for the request id in the session, then
 * a same-site relay page moves on (never an HTTP redirect, see PasswordResetLandingController).
 */
#[Route(path: '/email/confirm/{token}', name: RouteCollection::EMAIL_CHANGE_LANDING->value, requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
final class EmailChangeLandingController extends AbstractController
{
    public function __construct(
        private readonly EmailChangeRequestRepository $requests,
        private readonly TokenHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $change = $this->requests->findByTokenHash($this->hasher->hash($token));
        if (null === $change || !$change->isUsableAt($this->clock->now())) {
            return EmailChangeConfirmController::invalidPage($this->renderView('identity/security/email_change_invalid.html.twig'));
        }

        $request->getSession()->set(SessionKeys::EMAIL_CHANGE_REQUEST, $change->id()->toRfc4122());
        $response = $this->render('identity/security/continue.html.twig', [
            'next_route' => RouteCollection::EMAIL_CHANGE_CONFIRM->prefixed(),
            'title_key' => 'identity.email_change.title',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
