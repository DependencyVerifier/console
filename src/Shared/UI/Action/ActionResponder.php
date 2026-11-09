<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Security\RevealsSecret;
use Shared\UI\Frame\FrameEndpoint;
use Shared\UI\Http\RefusalResponseTable;
use Shared\UI\Routing\RouteReference;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The single point that runs an action of the authenticated area (execute around): the controller only builds the
 * form and the command.
 * 1. invalid CSRF token → CsrfRejection (403, one `csrf.rejected` entry);
 * 2. invalid form → 422 in the form's frame, no dispatch, no audit;
 * 3. consistency check before any dispatch: a RevealsSecret command needs SecretRevealingResponse and the reverse;
 * 4. dispatch;
 * 5. refusal (AlreadyAudited, already audited) → the form again with its values and the error, status from the
 *    refusal table;
 * 6. success → the ResponseFormat strategy.
 */
final readonly class ActionResponder
{
    public function __construct(
        private CommandBus $commands,
        private CsrfRejection $csrfRejection,
        private Environment $twig,
    ) {
    }

    /**
     * @param \Closure(): AuditableCommand $toCommand called once the form is valid (read its fields with FormData)
     */
    public function handle(Request $request, ActionForm $form, \Closure $toCommand, ResponseFormat $format, RouteReference $fallback): Response
    {
        $form->form->handleRequest($request);
        if (!$form->form->isSubmitted()) {
            return $this->render($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $csrfTokenId = CsrfFailure::tokenIdOf($form->form);
        if (null !== $csrfTokenId) {
            return $this->csrfRejection->reject($csrfTokenId);
        }

        if (!$form->form->isValid()) {
            return $this->render($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $command = $toCommand();
        if ($command instanceof RevealsSecret !== $format->revealsSecret()) {
            throw new \LogicException(\sprintf('%s and %s disagree on revealing a secret.', $command::class, $format::class));
        }

        try {
            $this->commands->dispatch($command);
        } catch (AlreadyAudited $audited) {
            $form->form->addError(new FormError(RefusalResponseTable::messageKeyOf($audited->cause())));

            return $this->render($form, RefusalResponseTable::statusOf($audited->cause()));
        }

        return $format->respond($request, $command, $form, $fallback);
    }

    private function render(ActionForm $form, int $status): Response
    {
        return FrameEndpoint::uncached(new Response($this->twig->render($form->template, [
            ...$form->templateContext,
            'form' => $form->form->createView(),
            'frame_id' => $form->frameId,
        ]), $status));
    }
}
