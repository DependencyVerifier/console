<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

/**
 * The "Create a team" dialog of /admin/teams: a generic FormDialog whose form comes from its own endpoint.
 */
final class AdminTeamCreateTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'create-team-form'];

    #[Test]
    public function it_should_offer_a_dialog_instead_of_an_inline_form(): void
    {
        $this->asSuperAdmin();
        $this->client->request('GET', '/admin/teams');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-controller~="form-dialog"][data-controller~="dialog"] button[aria-haspopup="dialog"]', 'Create a team');
        self::assertSelectorExists('dialog turbo-frame#create-team-form[src="/admin/teams/new"][loading="lazy"]');
        self::assertSelectorNotExists('main form input[name="team[name]"]', 'The form is not in the page.');
    }

    #[Test]
    public function it_should_serve_the_form_in_the_dialog_frame_only(): void
    {
        $this->asSuperAdmin();

        $this->client->request('GET', '/admin/teams/new');
        self::assertResponseRedirects('/admin/teams', 303);

        $this->client->request('GET', '/admin/teams/new', server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('turbo-frame#create-team-form form[action="/admin/teams/new"] input[name="team[firstAdminEmail]"]');
        self::assertSelectorExists('turbo-frame#create-team-form button[data-action="dialog#close"]');
    }

    #[Test]
    public function it_should_keep_an_invalid_form_in_the_dialog(): void
    {
        $this->asSuperAdmin();
        $form = $this->client->request('GET', '/admin/teams/new', server: self::FRAME)
            ->filter('turbo-frame#create-team-form form')->form(['team[name]' => 'Platform', 'team[firstAdminEmail]' => 'not an email']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...self::FRAME]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#create-team-form input[name="team[name]"][value="Platform"]');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_team'));
        self::assertSame([], AuditLog::entries($this->connection(), 'organization.create_team'), 'An invalid form dispatches nothing.');
    }

    #[Test]
    public function it_should_create_the_team_and_answer_the_page_streams(): void
    {
        $this->asSuperAdmin();
        $form = $this->client->request('GET', '/admin/teams/new', server: self::FRAME)
            ->filter('turbo-frame#create-team-form form')->form(['team[name]' => 'Platform', 'team[firstAdminEmail]' => 'jane@example.com']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...self::FRAME]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="action-alerts"', (string) $this->client->getResponse()->getContent());
        self::assertSame('Platform', $this->fetchString('SELECT name FROM organization_team'));
        self::assertEmailCount(1, message: 'The first admin is invited.');
    }

    #[Test]
    public function it_should_reject_a_submission_without_its_token(): void
    {
        $this->asSuperAdmin();
        $this->client->request('POST', '/admin/teams/new', ['team' => ['name' => 'Platform', 'firstAdminEmail' => 'jane@example.com']], server: ['HTTP_ACCEPT' => self::STREAM, ...self::FRAME]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'csrf.rejected'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_team'));
    }

    #[Test]
    public function it_should_keep_the_dialog_for_the_super_admin(): void
    {
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');

        $this->client->request('GET', '/admin/teams/new', server: self::FRAME);
        self::assertResponseStatusCodeSame(403);
    }

    private function asSuperAdmin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
    }
}
