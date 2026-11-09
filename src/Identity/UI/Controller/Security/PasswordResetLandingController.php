<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page of the emailed link: the token is exchanged for the request id in the session, then the browser is
 * sent to a URL without the token (it leaks neither through the Referer nor the history). Public: with
 * SameSite=Strict the first click from an email arrives without a session.
 */
#[Route(path: '/password/reset/{token}', name: RouteCollection::PASSWORD_RESET_LANDING->value, requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
final class PasswordResetLandingController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetRequestRepository $requests,
        private readonly TokenHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $resetRequest = $this->requests->findByTokenHash($this->hasher->hash($token));
        if (null === $resetRequest || !$resetRequest->isUsableAt($this->clock->now())) {
            $response = $this->render('identity/security/password_reset_invalid.html.twig', [], new Response(status: Response::HTTP_GONE));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $request->getSession()->set(SessionKeys::PASSWORD_RESET_REQUEST, $resetRequest->id()->toRfc4122());

        return $this->redirectToRoute(RouteCollection::PASSWORD_RESET_NEW->prefixed(), status: Response::HTTP_SEE_OTHER);
    }
}
