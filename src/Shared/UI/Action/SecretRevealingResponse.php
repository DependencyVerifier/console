<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commands that show a secret once (backup codes): the page is rendered in the response of the POST itself, 200,
 * never cached, never through a redirect, a flash or the session. The controller's closure decides what to show
 * (e.g. the codes only when the TOTP confirmation was accepted).
 */
final readonly class SecretRevealingResponse implements ResponseFormat
{
    /**
     * @param \Closure(AuditableCommand): Response $render
     */
    public function __construct(private \Closure $render)
    {
    }

    public function revealsSecret(): bool
    {
        return true;
    }

    public function respond(Request $request, AuditableCommand $command, ActionForm $form, RouteReference $fallback): Response
    {
        $response = ($this->render)($command);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
