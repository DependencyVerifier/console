<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\Authorization\Permission;

/**
 * Additive permissions on top of a role (stored as a JSON list of permission values).
 */
final class ExtraPermissions
{
    /**
     * @param list<string> $values
     *
     * @return list<Permission>
     */
    public static function decode(array $values): array
    {
        return array_values(array_filter(array_map(Permission::tryFrom(...), $values)));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function with(array $values, Permission $permission): array
    {
        return \in_array($permission->value, $values, true) ? $values : [...$values, $permission->value];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function without(array $values, Permission $permission): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== $permission->value));
    }
}
