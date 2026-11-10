<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

/**
 * The "Create a team" dialog of /admin/teams: a generic FormDialog whose form comes from its own endpoint. The first
 * admin is picked among the accounts (autocomplete) or typed as a new email; it is admitted at once.
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
    public function it_should_make_an_existing_account_team_admin_at_once(): void
    {
        $this->asSuperAdmin();
        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->submit('Platform', 'Jane@Example.com');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="action-alerts"', (string) $this->client->getResponse()->getContent());
        self::assertSame('Platform', $this->fetchString('SELECT name FROM organization_team'));
        $teamId = $this->fetchString('SELECT id FROM organization_team');
        self::assertSame('team_admin', $this->fetchString('SELECT role FROM organization_team_membership WHERE team_id = ? AND user_id = ?', [$teamId, $janeId]));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_invitation'), 'No invitation to accept.');
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM identity_user WHERE email = ?', ['jane@example.com']));

        self::assertEmailCount(1, message: 'The alert only: the account exists.');
        $alert = $this->lastEmail();
        self::assertSame('jane@example.com', $alert->getTo()[0]->getAddress());
        self::assertStringContainsString('https://localhost/teams/'.$teamId, (string) $alert->getTextBody());
    }

    #[Test]
    public function it_should_invite_an_unknown_email_and_make_it_team_admin(): void
    {
        $this->asSuperAdmin();
        $this->submit('Platform', 'new@example.com');

        self::assertResponseIsSuccessful();
        $userId = $this->fetchString('SELECT id FROM identity_user WHERE email = ?', ['new@example.com']);
        self::assertNull($this->connection()->fetchOne('SELECT password_hash FROM identity_user WHERE id = ?', [$userId]), 'Invited: no password yet.');
        $teamId = $this->fetchString('SELECT id FROM organization_team');
        self::assertSame('team_admin', $this->fetchString('SELECT role FROM organization_team_membership WHERE team_id = ? AND user_id = ?', [$teamId, $userId]));

        $subjects = array_map(static fn (object $m): string => $m instanceof Email ? (string) $m->getSubject() : '', self::getMailerMessages());
        sort($subjects);
        self::assertSame(['You manage the team Platform on DependencyVerifier', 'Your DependencyVerifier account'], $subjects);
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.create_team'));
    }

    #[Test]
    public function it_should_create_nothing_for_an_account_that_cannot_sign_in(): void
    {
        $this->asSuperAdmin();
        Accounts::create(self::getContainer(), 'off@example.com');
        $this->connection()->executeStatement("UPDATE identity_user SET status = 'disabled' WHERE email = 'off@example.com'");
        $this->submit('Platform', 'off@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#create-team-form', 'This account cannot be given a role');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_team'), 'One transaction: no team without its admin.');
        self::assertEmailCount(0);
        $denied = array_values(array_filter(AuditLog::entries($this->connection(), 'organization.create_team'), static fn (array $e): bool => 'denied' === $e['outcome']));
        self::assertSame('rule:account_not_assignable', $denied[0]['reason'] ?? null);
    }

    #[Test]
    public function it_should_suggest_the_accounts_that_may_be_admin(): void
    {
        $this->asSuperAdmin();
        Accounts::create(self::getContainer(), 'jane@example.com');
        Accounts::create(self::getContainer(), 'janet@example.com');
        Accounts::create(self::getContainer(), 'jane.off@example.com');
        $this->connection()->executeStatement("UPDATE identity_user SET status = 'disabled' WHERE email = 'jane.off@example.com'");

        $this->client->request('GET', '/admin/teams/new', server: self::FRAME);
        self::assertSelectorExists('input[name="team[firstAdminEmail]"][data-controller~="symfony--ux-autocomplete--autocomplete"][data-symfony--ux-autocomplete--autocomplete-url-value="/admin/accounts/search"]');

        $this->client->request('GET', '/admin/accounts/search', ['query' => 'JAN']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(['results' => [
            ['value' => 'jane@example.com', 'text' => 'jane@example.com'],
            ['value' => 'janet@example.com', 'text' => 'janet@example.com'],
        ]], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/admin/accounts/search', ['query' => '%a']);
        self::assertSame(['results' => []], json_decode((string) $this->client->getResponse()->getContent(), true), 'LIKE wildcards are escaped.');

        $this->client->getCookieJar()->clear();
        $this->logIn('jane@example.com');
        $this->client->request('GET', '/admin/accounts/search', ['query' => 'jan']);
        self::assertResponseStatusCodeSame(403);
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

    private function submit(string $name, string $firstAdmin): void
    {
        $form = $this->client->request('GET', '/admin/teams/new', server: self::FRAME)
            ->filter('turbo-frame#create-team-form form')->form(['team[name]' => $name, 'team[firstAdminEmail]' => $firstAdmin]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...self::FRAME]);
    }

    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function asSuperAdmin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
    }
}
