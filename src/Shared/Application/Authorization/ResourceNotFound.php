<?php

declare(strict_types=1);

namespace Shared\Application\Authorization;

/**
 * The object does not exist or is outside the actor's scope (they must not learn it exists): HTTP 404, audited
 * `denied` once.
 */
final class ResourceNotFound extends \RuntimeException
{
    public static function of(string $type): self
    {
        return new self(\sprintf('%s not found.', $type));
    }
}
