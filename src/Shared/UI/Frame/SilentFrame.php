<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

/**
 * A frame that never shows an error: a refusal or a failure of its endpoint renders it empty (same status, same
 * audit, same log), so an optional zone (a banner) never puts a red alert on a page. Every case of an enum
 * implementing it is silent.
 */
interface SilentFrame extends Frame
{
}
