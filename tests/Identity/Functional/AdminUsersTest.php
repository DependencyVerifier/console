<?php

declare(strict_types=1);

namespace Tests\Identity\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

final class AdminUsersTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_render_the_page_at_once_and_the_list_in_a_lazy_frame(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#admin-users[src="/admin/users/frames/list"][loading="lazy"]');
        self::assertSelectorNotExists('[style]');

        $this->client->request('GET', '/admin/users/frames/list');
        self::assertResponseStatusCodeSame(303, 'A frame is never a back door.');
        self::assertResponseRedirects('/admin/users');

        $this->client->request('GET', '/admin/users/frames/list', server: ['HTTP_TURBO_FRAME' => 'admin-users']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#admin-users', 'jane@example.com');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
    }

    #[Test]
    public function it_should_deactivate_an_account_and_refresh_the_list_with_a_stream(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('root@example.com');

        $crawler = $this->client->request('GET', '/admin/users/frames/list', server: ['HTTP_TURBO_FRAME' => 'admin-users']);
        $form = $crawler->filter(\sprintf('form[action="/admin/users/%s/deactivate"]', $jane->id()->toString()))->form();
        $this->client->submit($form, [], ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="admin-users">', $content);
        self::assertStringContainsString('Account deactivated.', $content);
        $entries = AuditLog::entries($this->connection(), 'identity.deactivate_user');
        self::assertCount(1, $entries);
        self::assertSame('success', $entries[0]['outcome']);
        self::assertSame($jane->id()->toString(), $entries[0]['target_id']);
    }

    #[Test]
    public function it_should_render_a_refusal_inside_the_frame_and_audit_it_once(): void
    {
        $root = Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $crawler = $this->client->request('GET', '/admin/users/frames/list', server: ['HTTP_TURBO_FRAME' => 'admin-users']);
        $form = $crawler->filter(\sprintf('form[action="/admin/users/%s/deactivate"]', $root->id()->toString()))->form();
        $this->client->submit($form, [], ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#admin-users [role="alert"]');
        $entries = AuditLog::entries($this->connection(), 'identity.deactivate_user');
        self::assertCount(1, $entries);
        self::assertSame('denied', $entries[0]['outcome']);
        self::assertSame('rule:self_management_forbidden', $entries[0]['reason']);
    }

    #[Test]
    public function it_should_keep_the_administration_to_super_admins(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/users/frames/list', server: ['HTTP_TURBO_FRAME' => 'admin-users']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, AuditLog::entries($this->connection(), 'security.access_denied'));
    }
}
