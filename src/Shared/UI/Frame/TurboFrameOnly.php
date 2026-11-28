<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

/**
 * A frame endpoint that only answers inside a Turbo Frame: a request without a `Turbo-Frame` header gets a 404
 * (TurboFrameOnlyListener) instead of FrameEndpoint's 303 to the fallback page. For a frame that has no page of its
 * own to show outside the layout, so that the endpoint stays invisible to a direct visit.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class TurboFrameOnly
{
}
