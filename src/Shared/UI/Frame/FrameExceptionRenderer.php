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
 * by RefusalExceptionListener just before).
 */
#[AsEventListener(KernelEvents::EXCEPTION, priority: 0)]
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
        $event->setResponse(FrameEndpoint::uncached(new Response($this->renderer->error($frame, $status, $context), $status)));
        $event->allowCustomResponseCode();
    }
}
