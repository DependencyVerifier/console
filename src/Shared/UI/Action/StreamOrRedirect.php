<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AuditableCommand;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameRefresher;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Turbo Stream when accepted (one `replace` per visible frame of the action, `remove` for a frame no longer
 * visible, plus a success Alert), else a 303 to the fallback page with a flash message.
 */
final readonly class StreamOrRedirect implements ResponseFormat
{
    public const string STREAM_FORMAT = 'text/vnd.turbo-stream.html';

    public function __construct(
        private FrameRefresher $refresher,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private string $successKey = 'action.done',
    ) {
    }

    public function withSuccessKey(string $successKey): self
    {
        return new self($this->refresher, $this->twig, $this->urls, $successKey);
    }

    public function revealsSecret(): bool
    {
        return false;
    }

    public function respond(Request $request, AuditableCommand $command, ActionForm $form, RouteReference $fallback): Response
    {
        if (\in_array(self::STREAM_FORMAT, $request->getAcceptableContentTypes(), true)) {
            $context = FrameContext::fromRouteParameters($request->attributes->get('_route_params'));

            return new Response($this->twig->render('shared/frame/action_streams.html.twig', [
                'streams' => $this->refresher->streams($command->auditAction(), $context),
                'success_key' => $this->successKey,
            ]), Response::HTTP_OK, ['Content-Type' => self::STREAM_FORMAT, 'Cache-Control' => 'private, no-store']);
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $this->successKey);
        }

        return new RedirectResponse($this->urls->generate($fallback->name, $fallback->parameters), Response::HTTP_SEE_OTHER);
    }
}
