<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * Soft delete from /admin/users: the account leaves the list, "Show deleted" brings it back with Restore, which
 * puts it back as deactivated.
 */
final class AdminUserDeleteTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_hide_a_deleted_account_and_restore_it_as_deactivated(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->logIn('root@example.com');

        $this->post($this->rowOf('/admin/users', 'jane@example.com'), \sprintf('/admin/users/%s/delete', $janeId));
        self::assertResponseIsSuccessful();
        self::assertSame('deleted', $this->fetchString('SELECT status FROM identity_user WHERE id = ?', [$janeId]));
        self::assertCount(1, AuditLog::entries($this->connection(), 'identity.delete_user'));
        self::assertSame(1, DataTables::draw($this->client, '/admin/users', 'users')['recordsTotal'], 'Hidden by default.');

        $this->client->request('GET', '/admin/users?deleted=1');
        self::assertSelectorExists('[data-datatable-url-value="/admin/users?deleted=1"]', 'The table keeps the filter.');
        $row = $this->rowOf('/admin/users?deleted=1', 'jane@example.com');
        self::assertStringContainsString('Deleted', $row['active']);
        self::assertCount(0, DataTables::cell($row['actions'])->filter('form[action$="/delete"]'), 'Only Restore on a deleted account.');

        $this->post($row, \sprintf('/admin/users/%s/restore', $janeId));
        self::assertResponseIsSuccessful();
        self::assertSame('disabled', $this->fetchString('SELECT status FROM identity_user WHERE id = ?', [$janeId]), 'Restored, still deactivated.');
        self::assertSame(2, DataTables::draw($this->client, '/admin/users', 'users')['recordsTotal']);
    }

    #[Test]
    public function it_should_refuse_to_delete_oneself(): void
    {
        $rootId = Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true)->id()->toString();
        Accounts::create(self::getContainer(), 'other@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $this->post($this->rowOf('/admin/users', 'root@example.com'), \sprintf('/admin/users/%s/delete', $rootId));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('active', $this->fetchString('SELECT status FROM identity_user WHERE id = ?', [$rootId]));
    }

    /**
     * @return array<string, string>
     */
    private function rowOf(string $url, string $email): array
    {
        foreach (DataTables::draw($this->client, $url, 'users')['data'] as $row) {
            if (u($row['email'])->containsAny($email)) {
                return $row;
            }
        }

        self::fail('No row for '.$email);
    }

    /**
     * @param array<string, string> $row
     */
    private function post(array $row, string $action): void
    {
        $form = DataTables::cell($row['actions'])->filter(\sprintf('form[action="%s"]', $action))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
    }
}
