<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Invitation;

use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page of the invitation email: the token is exchanged for the invitation id in the session, then a same-site
 * hop (no HTTP redirect: it would keep the cross-site context and drop the SameSite=Strict cookie) to a URL without
 * the token.
 */
#[Route(path: '/invitations/{token}', name: RouteCollection::INVITATION_LANDING->value, requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
final class InvitationLandingController extends AbstractController
{
    public function __construct(private readonly InvitationPages $pages, private readonly QueryBus $queries, private readonly TokenHasher $hasher)
    {
    }

    public function __invoke(Request $request, string $token): Response
    {
        try {
            $invitation = $this->queries->ask(FindPendingInvitation::byTokenHash($this->hasher->hash($token)));
        } catch (AlreadyAudited) {
            return $this->pages->invalid();
        }

        $request->getSession()->set(SessionKeys::INVITATION, $invitation->invitationId);
        $response = $this->render('identity/security/continue.html.twig', [
            'next_route' => RouteCollection::INVITATION_PENDING->prefixed(),
            'title_key' => 'identity.invitation.title',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
