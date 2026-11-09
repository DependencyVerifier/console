<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Psr\Log\LoggerInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditAction;
use Shared\Infrastructure\Security\RequestIdHolder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Environment;

/**
 * After a successful command: one Turbo Stream per frame of the action. A frame no longer visible gets `remove`
 * (no reread, no `denied`); each frame is recomposed on its own, and a failing one gets its error template while
 * the response stays a success ("what is committed is never denied"). The log of that failure holds the exception
 * class, the frame id and the request id only.
 */
final readonly class FrameRefresher
{
    /**
     * @param iterable<ActionFrameMap> $maps
     */
    public function __construct(
        #[AutowireIterator(ActionFrameMap::class)]
        private iterable $maps,
        private FrameRenderer $renderer,
        private Environment $twig,
        private LoggerInterface $logger,
        private RequestIdHolder $requestId,
    ) {
    }

    /**
     * @return list<string> rendered <turbo-stream> elements
     */
    public function streams(AuditAction $action, FrameContext $context): array
    {
        $streams = [];
        foreach ($this->maps as $map) {
            foreach ($map->framesFor($action) as $frame) {
                $streams[] = $this->stream($frame, $context);
            }
        }

        return $streams;
    }

    private function stream(Frame $frame, FrameContext $context): string
    {
        $view = $this->renderer->viewOf($frame);
        if (!$view->isVisible($context)) {
            return $this->twig->render('shared/frame/stream.html.twig', ['action' => 'remove', 'target' => $frame->id(), 'content' => '']);
        }

        try {
            $content = $this->renderer->render($frame, $context);
        } catch (\Throwable $failure) {
            $this->logger->error('A frame could not be refreshed after an action.', [
                'exception_class' => ($failure instanceof AlreadyAudited ? $failure->cause() : $failure)::class,
                'frame' => $frame->id(),
                'request_id' => $this->requestId->current(),
            ]);
            $content = $this->renderer->error($frame, 500, $context);
        }

        return $this->twig->render('shared/frame/stream.html.twig', ['action' => 'replace', 'target' => $frame->id(), 'content' => $content]);
    }
}
