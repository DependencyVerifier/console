<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Identity\Application\Query\User\UserDirectory;
use Identity\Application\Query\User\UserSummary;

final readonly class DbalUserDirectory implements UserDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function all(): array
    {
        /** @var list<array{id: string, email: string, super_admin: bool, status: string, credentials_kind: string, instance_permissions: string, second_factor_kind: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, email, super_admin, status, credentials_kind, instance_permissions, second_factor_kind, created_at FROM identity_user ORDER BY email',
        );

        return array_map(static function (array $row): UserSummary {
            $permissions = json_decode($row['instance_permissions'], true, 4, \JSON_THROW_ON_ERROR);
            $active = 'active' === $row['status'];

            return new UserSummary(
                $row['id'],
                $row['email'],
                (bool) $row['super_admin'],
                $active,
                $active && 'password' === $row['credentials_kind'] && \is_array($permissions) && \in_array('account.login', $permissions, true),
                'active_totp' === $row['second_factor_kind'],
                new \DateTimeImmutable($row['created_at']),
            );
        }, $rows);
    }
}
