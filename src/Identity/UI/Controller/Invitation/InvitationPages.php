<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Invitation;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The page of an invitation that is accepted, revoked, expired or unknown (same answer for all, 410).
 */
final readonly class InvitationPages
{
    public function __construct(private Environment $twig)
    {
    }

    public function invalid(): Response
    {
        return new Response($this->twig->render('identity/invitation/invalid.html.twig'), Response::HTTP_GONE, ['Cache-Control' => 'no-store']);
    }
}
