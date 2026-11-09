<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strategy of the answer to a successful command: Turbo Stream or 303 (StreamOrRedirect), or the one-time page of a
 * secret (SecretRevealingResponse).
 */
interface ResponseFormat
{
    public function revealsSecret(): bool;

    public function respond(Request $request, AuditableCommand $command, ActionForm $form, RouteReference $fallback): Response;
}
