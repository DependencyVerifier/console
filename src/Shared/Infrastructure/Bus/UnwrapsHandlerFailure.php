<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Rethrows the handler's own exception instead of Messenger's wrapper, so callers catch domain exceptions.
 */
trait UnwrapsHandlerFailure
{
    /**
     * @template T
     *
     * @param callable(): T $dispatch
     *
     * @return T
     */
    private function unwrap(callable $dispatch): mixed
    {
        try {
            return $dispatch();
        } catch (HandlerFailedException $exception) {
            throw $exception->getPrevious() ?? $exception;
        }
    }
}
