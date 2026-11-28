<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders a refusal of a frame endpoint inside <turbo-frame id="…"> with its status (403, 404, 422, 503, 500), so
 * Turbo swaps it in place. Never audits: the refusal is already audited (AlreadyAudited, turned into an HTTP status
 * by RefusalExceptionListener just before). A SilentFrame is rendered empty, with the same status. The priority is
 * below the ErrorListener's logging (0), which a response set here would otherwise stop: a 500 is always logged.
 */
#[AsEventListener(KernelEvents::EXCEPTION, priority: -1)]
final readonly class FrameExceptionRenderer
{
    public function __construct(private FrameRenderer $renderer)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $frame = $event->getRequest()->attributes->get(FrameEndpoint::ROUTE_ATTRIBUTE);
        if (!$frame instanceof Frame) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $context = FrameContext::fromRouteParameters($event->getRequest()->attributes->get('_route_params'));
        $content = $frame instanceof SilentFrame ? $this->renderer->wrap($frame, '') : $this->renderer->error($frame, $status, $context);
        $event->setResponse(FrameEndpoint::uncached(new Response($content, $status)));
        $event->allowCustomResponseCode();
    }
}
