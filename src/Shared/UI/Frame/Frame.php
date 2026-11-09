<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Shared\UI\Routing\RouteReference;

/**
 * A lazily loaded zone of a page (decision 31). Each context implements it with a `TurboFrame` enum whose cases
 * carry these presentation methods. `fallbackRoute` is a page of the same context showing this frame alone (where a
 * request without the Turbo-Frame header is redirected); a frame never knows the page of another context.
 */
interface Frame
{
    /** DOM id of the <turbo-frame>. */
    public function id(): string;

    /** Full name of the frame endpoint route. */
    public function route(): string;

    public function fallbackRoute(FrameContext $context): RouteReference;
}
