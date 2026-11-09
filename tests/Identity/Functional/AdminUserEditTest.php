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
 * The "Edit" dialog of each row of /admin/users: the display name, then the instance role (super admin) through
 * its own re-authenticated buttons.
 */
final class AdminUserEditTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_rename_an_account_shown_then_in_the_list_and_its_sidebar(): void
    {
        $janeId = $this->setUpAccounts();
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-user-%s-form', $janeId)];

        $actions = DataTables::cell($this->row('jane@example.com')['actions']);
        self::assertSame(\sprintf('/admin/users/%s/edit', $janeId), $actions->filter(\sprintf('turbo-frame#edit-user-%s-form', $janeId))->attr('src'));

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/edit', $janeId), server: $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('turbo-frame#edit-user-%s-form form[action="/admin/users/%s/grant-super-admin"]', $janeId, $janeId), 'The role, in the dialog.');
        $form = $crawler->filter(\sprintf('form[action="/admin/users/%s/edit"]', $janeId))->form(['user_edit[displayName]' => 'Jane Doe']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseIsSuccessful();
        self::assertSame('Jane Doe', $this->fetchString('SELECT display_name FROM identity_user WHERE id = ?', [$janeId]));
        self::assertCount(1, AuditLog::entries($this->connection(), 'identity.update_user'));
        self::assertStringContainsString('Jane Doe', $this->row('jane@example.com')['email']);
        self::assertSame(1, DataTables::draw($this->client, '/admin/users', 'users', 'jane doe')['recordsFiltered'], 'Searchable by name.');

        $this->client->getCookieJar()->clear();
        $this->logIn('jane@example.com');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('[data-slot="sidebar"]', 'Jane Doe');
    }

    #[Test]
    public function it_should_keep_a_too_long_name_in_the_dialog(): void
    {
        $janeId = $this->setUpAccounts();
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-user-%s-form', $janeId)];
        $form = $this->client->request('GET', \sprintf('/admin/users/%s/edit', $janeId), server: $frame)
            ->filter(\sprintf('form[action="/admin/users/%s/edit"]', $janeId))->form(['user_edit[displayName]' => u('j')->repeat(101)->toString()]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->connection()->fetchOne('SELECT display_name FROM identity_user WHERE id = ?', [$janeId]));
    }

    private function setUpAccounts(): string
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->logIn('root@example.com');

        return $janeId;
    }

    /**
     * @return array<string, string>
     */
    private function row(string $email): array
    {
        foreach (DataTables::draw($this->client, '/admin/users', 'users')['data'] as $row) {
            if (u($row['email'])->containsAny($email)) {
                return $row;
            }
        }

        self::fail('No row for '.$email);
    }
}
