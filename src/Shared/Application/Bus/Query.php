<?php

declare(strict_types=1);

namespace Shared\Application\Bus;

/**
 * Marker for a read request. Must not change state.
 *
 * @template-covariant TResult
 */
interface Query
{
}
