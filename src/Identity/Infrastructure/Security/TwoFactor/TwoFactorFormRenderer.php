<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security\TwoFactor;

use Identity\Infrastructure\Security\SessionKeys;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class TwoFactorFormRenderer implements TwoFactorFormRendererInterface
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $templateVars
     */
    public function renderForm(Request $request, array $templateVars): Response
    {
        $session = $request->getSession();
        $error = $session->get(SessionKeys::AUTH_ERROR);
        $session->remove(SessionKeys::AUTH_ERROR);

        return new Response($this->twig->render('identity/security/two_factor.html.twig', [
            ...$templateVars,
            'error_key' => \is_string($error) ? $error : null,
            'email_code' => EmailCodeProvider::NAME === ($templateVars['twoFactorProvider'] ?? null),
            'passkey' => PasskeyProvider::NAME === ($templateVars['twoFactorProvider'] ?? null),
        ]), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
