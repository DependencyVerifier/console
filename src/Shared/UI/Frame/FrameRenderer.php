<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Environment;

/**
 * Finds the single FrameView of a frame and wraps its content in <turbo-frame id="…">.
 */
final readonly class FrameRenderer
{
    /**
     * @param iterable<FrameView> $views
     */
    public function __construct(
        #[AutowireIterator(FrameView::class)]
        private iterable $views,
        private Environment $twig,
    ) {
    }

    public function viewOf(Frame $frame): FrameView
    {
        foreach ($this->views as $view) {
            if ($view->frame() === $frame) {
                return $view;
            }
        }

        throw new \LogicException(\sprintf('No FrameView for frame "%s".', $frame->id()));
    }

    public function render(Frame $frame, FrameContext $context): string
    {
        return $this->wrap($frame, $this->viewOf($frame)->render($context));
    }

    public function wrap(Frame $frame, string $content): string
    {
        return $this->twig->render('shared/frame/frame.html.twig', ['id' => $frame->id(), 'content' => $content]);
    }

    public function error(Frame $frame, int $status, FrameContext $context): string
    {
        return $this->twig->render('shared/frame/error.html.twig', [
            'id' => $frame->id(),
            'status' => $status,
            'retry_url' => null,
            'context' => $context->parameters,
        ]);
    }
}
