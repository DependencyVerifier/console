<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\UI;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Shared\UI\Frame\FrameExceptionRenderer;
use Shared\UI\Frame\FrameRenderer;
use Shared\UI\Frame\SilentFrame;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A refusal or a failure of a frame endpoint: the error alert for a frame, nothing for a silent frame, the same
 * status for both.
 */
final class FrameExceptionRendererTest extends TestCase
{
    #[Test]
    public function it_should_render_the_error_of_a_frame_and_nothing_for_a_silent_frame(): void
    {
        $renderer = new FrameExceptionRenderer(new FrameRenderer([], new Environment(new ArrayLoader([
            'shared/frame/frame.html.twig' => '<turbo-frame id="{{ id }}">{{ content|raw }}</turbo-frame>',
            'shared/frame/error.html.twig' => '<turbo-frame id="{{ id }}"><div role="alert">{{ status }}</div></turbo-frame>',
        ]))));

        foreach ([[new AccessDeniedHttpException(), 403], [PermissionDenied::for('x'), 500], [new \LogicException(), 500]] as [$exception, $status]) {
            $normal = $this->event($this->frame('normal', silent: false), $exception);
            $renderer($normal);
            self::assertSame(\sprintf('<turbo-frame id="normal"><div role="alert">%d</div></turbo-frame>', $status), $normal->getResponse()?->getContent());
            self::assertSame($status, $normal->getResponse()->getStatusCode());

            $silent = $this->event($this->frame('banner', silent: true), $exception);
            $renderer($silent);
            self::assertSame('<turbo-frame id="banner"></turbo-frame>', $silent->getResponse()?->getContent());
            self::assertSame($status, $silent->getResponse()->getStatusCode(), 'Silent, never a success.');
            self::assertTrue($silent->getResponse()->headers->hasCacheControlDirective('no-store'));
        }
    }

    #[Test]
    public function it_should_leave_any_other_request_alone(): void
    {
        $renderer = new FrameExceptionRenderer(new FrameRenderer([], new Environment(new ArrayLoader([]))));
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \LogicException());

        $renderer($event);

        self::assertNull($event->getResponse());
    }

    private function event(Frame $frame, \Throwable $exception): ExceptionEvent
    {
        $request = new Request();
        $request->attributes->set(FrameEndpoint::ROUTE_ATTRIBUTE, $frame);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    private function frame(string $id, bool $silent): Frame
    {
        return $silent
            ? new readonly class($id) implements SilentFrame {
                public function __construct(private string $id)
                {
                }

                public function id(): string
                {
                    return $this->id;
                }

                public function route(): string
                {
                    return 'x';
                }

                public function fallbackRoute(FrameContext $context): RouteReference
                {
                    return new RouteReference('x');
                }
            }
        : new readonly class($id) implements Frame {
            public function __construct(private string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function route(): string
            {
                return 'x';
            }

            public function fallbackRoute(FrameContext $context): RouteReference
            {
                return new RouteReference('x');
            }
        };
    }
}
