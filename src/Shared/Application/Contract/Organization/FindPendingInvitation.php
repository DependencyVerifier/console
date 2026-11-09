<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: a pending invitation, by the hash of its token (landing page) or by its id (next
 * steps). Not found when accepted, revoked or expired.
 *
 * @implements Query<PendingInvitation>
 */
final readonly class FindPendingInvitation implements Query
{
    private function __construct(public ?string $tokenHash, public ?string $invitationId)
    {
    }

    public static function byTokenHash(string $tokenHash): self
    {
        return new self($tokenHash, null);
    }

    public static function byId(string $invitationId): self
    {
        return new self(null, $invitationId);
    }
}
