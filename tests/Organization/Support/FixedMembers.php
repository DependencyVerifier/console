<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Application\Security\MemberPermissions;
use Organization\Domain\Authorization\EffectivePermissions;

/**
 * The permissions of any account, fixed by id (none for an unknown one).
 */
final readonly class FixedMembers implements MemberPermissions
{
    /**
     * @param array<string, EffectivePermissions> $members
     */
    public function __construct(private array $members)
    {
    }

    public function of(string $userId): EffectivePermissions
    {
        return $this->members[$userId] ?? EffectivePermissions::resolve(false, [], []);
    }
}
