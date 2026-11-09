<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every `…/frames/<name>` controller does: no Turbo-Frame header → 303 to the fallback page of the same
 * context (a frame is never a back door), else the frame rendered through its FrameView, always inside
 * <turbo-frame id>, private and never cached. Refusals are rendered by FrameExceptionRenderer.
 */
final readonly class FrameEndpoint
{
    public const string ROUTE_ATTRIBUTE = '_frame';

    public function __construct(private FrameRenderer $renderer, private UrlGeneratorInterface $urls)
    {
    }

    public function respond(Request $request, Frame $frame, FrameContext $context): Response
    {
        if (!$request->headers->has('Turbo-Frame')) {
            $fallback = $frame->fallbackRoute($context);

            return new RedirectResponse($this->urls->generate($fallback->name, $fallback->parameters), Response::HTTP_SEE_OTHER);
        }

        $request->attributes->set(self::ROUTE_ATTRIBUTE, $frame);

        return self::uncached(new Response($this->renderer->render($frame, $context)));
    }

    public static function uncached(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary(['Accept', 'Turbo-Frame']);

        return $response;
    }
}
