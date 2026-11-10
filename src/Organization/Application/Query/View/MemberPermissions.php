<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class MemberPermissions
{
    /**
     * @param list<PermissionChoice> $choices in the order of the §7bis matrix
     */
    public function __construct(public string $userId, public string $role, public array $choices)
    {
    }

    /**
     * @return list<string>
     */
    public function granted(): array
    {
        return array_values(array_map(static fn (PermissionChoice $c): string => $c->permission, array_filter($this->choices, static fn (PermissionChoice $c): bool => $c->granted)));
    }
}
