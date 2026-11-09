<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Frame\FrameEndpoint;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The form endpoint behind a <twig:FormDialog> (one controller per form, GET|POST on its own URL):
 * - GET renders the form alone in the dialog's frame, never cached; without the Turbo-Frame header, a 303 to the
 *   page (as for any frame: an endpoint is never a page of its own);
 * - POST goes through ActionResponder: a 422 or a refusal renders the form again in the same frame, a success
 *   answers the ResponseFormat (Turbo Stream: lists reloaded, alert on the page; the dialog closes itself).
 */
final readonly class DialogForm
{
    public const string TEMPLATE = 'shared/dialog/form.html.twig';

    /** The frame id of a dialog, `<dialog id>-form` (the same rule in templates/components/FormDialog.html.twig). */
    public const string FRAME_SUFFIX = '-form';

    public function __construct(private ActionResponder $responder, private Environment $twig, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param FormInterface<covariant mixed> $form
     * @param \Closure(): AuditableCommand   $toCommand called once the form is valid (read its fields with FormData)
     */
    public function handle(Request $request, FormInterface $form, string $dialogId, string $submitKey, \Closure $toCommand, ResponseFormat $format, RouteReference $page): Response
    {
        $action = new ActionForm($form, $dialogId.self::FRAME_SUFFIX, self::TEMPLATE, [
            'action' => $request->getBaseUrl().$request->getPathInfo(),
            'submit_key' => $submitKey,
        ]);

        if ($request->isMethod('POST')) {
            return $this->responder->handle($request, $action, $toCommand, $format, $page);
        }

        if (!$request->headers->has('Turbo-Frame')) {
            return new RedirectResponse($this->urls->generate($page->name, $page->parameters), Response::HTTP_SEE_OTHER);
        }

        return FrameEndpoint::uncached(new Response($this->twig->render(self::TEMPLATE, [
            ...$action->templateContext,
            'form' => $form->createView(),
            'frame_id' => $action->frameId,
        ])));
    }
}
