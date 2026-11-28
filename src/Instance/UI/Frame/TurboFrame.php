<?php

declare(strict_types=1);

namespace Instance\UI\Frame;

use Instance\UI\Controller\RouteCollection;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\SilentFrame;
use Shared\UI\Routing\RouteReference;

/**
 * Lazy zones of Instance. All silent: the page never shows an error because upstream or the banner failed.
 */
enum TurboFrame: string implements SilentFrame
{
    case ConsoleUpdate = 'console-update';

    public function id(): string
    {
        return $this->value;
    }

    public function route(): string
    {
        return match ($this) {
            self::ConsoleUpdate => RouteCollection::CONSOLE_UPDATE_FRAME->prefixed(),
        };
    }

    public function fallbackRoute(FrameContext $context): RouteReference
    {
        return match ($this) {
            self::ConsoleUpdate => new RouteReference(RouteCollection::ABOUT->prefixed()),
        };
    }
}
