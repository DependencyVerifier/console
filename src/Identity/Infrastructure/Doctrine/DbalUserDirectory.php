<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Identity\Application\Query\User\UserDirectory;
use Identity\Application\Query\User\UserSummary;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Infrastructure\Doctrine\Row;

use function Symfony\Component\String\u;

final readonly class DbalUserDirectory implements UserDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(PageRequest $page): Page
    {
        $total = Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM identity_user'));
        $where = '';
        $parameters = [];
        if ('' !== $page->search) {
            $where = ' WHERE email LIKE :search';
            $parameters['search'] = '%'.u($page->search)->lower()->replace('\\', '\\\\')->replace('%', '\\%')->replace('_', '\\_')->toString().'%';
        }

        $filtered = Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM identity_user'.$where, $parameters));
        $order = ['email' => 'email', 'created_at' => 'created_at'][$page->sortAmong(['email', 'created_at'], 'email')];

        /** @var list<array{id: string, email: string, super_admin: bool, status: string, credentials_kind: string, instance_permissions: string, second_factor_kind: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT id, email, super_admin, status, credentials_kind, instance_permissions, second_factor_kind, created_at FROM identity_user%s ORDER BY %s %s LIMIT %d OFFSET %d', $where, $order, $page->descending ? 'DESC' : 'ASC', $page->limit, $page->offset),
            $parameters,
        );

        return new Page(array_map(static function (array $row): UserSummary {
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
        }, $rows), $filtered, $total);
    }

    public function byIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{id: string, email: string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, email, status FROM identity_user WHERE id IN (:ids)',
            ['ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): AccountView => new AccountView($row['id'], $row['email'], 'active' === $row['status']), $rows);
    }
}
