<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

final class AdminUsersTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_render_the_page_at_once_and_draw_the_list_server_side(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="datatable"][data-datatable-name-value="users"] table');
        self::assertSelectorNotExists('[style]');

        $draw = DataTables::draw($this->client, '/admin/users', 'users');
        self::assertSame(2, $draw['recordsTotal']);
        self::assertStringContainsString('jane@example.com', $draw['data'][0]['email']);

        $search = DataTables::draw($this->client, '/admin/users', 'users', 'JANE');
        self::assertSame(1, $search['recordsFiltered']);
        self::assertSame(2, $search['recordsTotal']);
    }

    #[Test]
    public function it_should_escape_cells(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $draw = DataTables::draw($this->client, '/admin/users', 'users');

        self::assertStringNotContainsString('<script', $draw['data'][0]['email']);
        self::assertStringContainsString('<form method="post"', $draw['data'][0]['actions']);
    }

    #[Test]
    public function it_should_deactivate_an_account_with_a_stream_answer(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/users');
        $row = array_first(array_filter(DataTables::draw($this->client, '/admin/users', 'users')['data'], static fn (array $r): bool => u($r['email'])->containsAny('jane@')));
        self::assertIsArray($row);
        $form = DataTables::cell($row['actions'])->filter(\sprintf('form[action="/admin/users/%s/deactivate"]', $jane->id()->toString()))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Account deactivated.', (string) $this->client->getResponse()->getContent());
        $entries = AuditLog::entries($this->connection(), 'identity.deactivate_user');
        self::assertCount(1, $entries);
        self::assertSame('success', $entries[0]['outcome']);
        self::assertSame($jane->id()->toString(), $entries[0]['target_id']);
    }

    #[Test]
    public function it_should_answer_a_refusal_as_a_stream_and_audit_it_once(): void
    {
        $root = Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $row = DataTables::draw($this->client, '/admin/users', 'users')['data'][0];
        $form = DataTables::cell($row['actions'])->filter(\sprintf('form[action="/admin/users/%s/deactivate"]', $root->id()->toString()))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('<turbo-stream action="update" target="action-alerts">', (string) $this->client->getResponse()->getContent());
        $entries = AuditLog::entries($this->connection(), 'identity.deactivate_user');
        self::assertCount(1, $entries);
        self::assertSame('rule:self_management_forbidden', $entries[0]['reason']);
    }

    #[Test]
    public function it_should_keep_the_administration_to_super_admins(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/users', ['_dt' => 'users', 'draw' => 1, 'start' => 0, 'length' => 10]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, AuditLog::entries($this->connection(), 'security.access_denied'));
    }
}
