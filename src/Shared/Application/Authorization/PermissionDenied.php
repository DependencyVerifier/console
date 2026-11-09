<?php

declare(strict_types=1);

namespace Shared\Application\Authorization;

/**
 * The actor sees the object but lacks the permission for this action: HTTP 403, audited `denied` once.
 */
final class PermissionDenied extends \RuntimeException
{
    public static function for(string $permission): self
    {
        return new self(\sprintf('Permission "%s" required.', $permission));
    }
}
