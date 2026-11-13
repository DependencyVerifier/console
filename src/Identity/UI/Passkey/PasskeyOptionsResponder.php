<?php

declare(strict_types=1);

namespace Identity\UI\Passkey;

use Identity\Application\Port\PasskeyOptions;
use Identity\Infrastructure\Security\SessionKeys;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The options of a ceremony for passkey_controller.ts (`POST`, the CSRF token of the form that will carry the answer
 * in `X-CSRF-Token`): kept in the session until that answer (one ceremony at a time), never cached.
 */
final readonly class PasskeyOptionsResponder
{
    public const string HEADER = 'X-CSRF-Token';

    public function __construct(private CsrfTokenManagerInterface $csrf, private CsrfRejection $rejection)
    {
    }

    /**
     * @param \Closure(): PasskeyOptions $options
     */
    public function respond(Request $request, string $csrfTokenId, \Closure $options): Response
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($csrfTokenId, (string) $request->headers->get(self::HEADER)))) {
            return $this->rejection->reject($csrfTokenId);
        }

        $json = $options()->json;
        $request->getSession()->set(SessionKeys::PASSKEY_OPTIONS, $json);

        return new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    /**
     * The options of the ceremony this session started, once (a second answer finds none).
     */
    public static function take(Request $request): string
    {
        $session = $request->getSession();
        $options = $session->get(SessionKeys::PASSKEY_OPTIONS);
        $session->remove(SessionKeys::PASSKEY_OPTIONS);

        return \is_string($options) ? $options : '';
    }
}
