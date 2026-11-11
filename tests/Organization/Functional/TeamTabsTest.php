<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Identity\Domain\Model\User;
use Organization\Application\Command\QuotaInput;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * The team page in four sections, each its own URL, picked in the sidebar: Overview, Projects, Members (not for a
 * guest), Settings (`team.edit`). The creation forms open in dialogs.
 */
final class TeamTabsTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_show_each_tab_on_its_own_url_with_the_tabs_of_the_role(): void
    {
        $team = $this->team();
        $id = $team->id()->toString();
        $this->logIn('alice@example.com');

        foreach (['' => 'Overview', '/projects' => 'Projects', '/members' => 'Team members', '/settings' => 'Team settings'] as $suffix => $label) {
            $this->client->request('GET', '/teams/'.$id.$suffix);
            self::assertResponseIsSuccessful($label);
            self::assertSelectorTextSame('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', $label, 'The section is picked in the sidebar.');
            self::assertSame('/teams/'.$id.$suffix, $this->client->getCrawler()->filter('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]')->attr('href'));
        }
        self::assertSame(['Overview', 'Projects', 'Team members', 'Hosts', 'Agents', 'Enrollment', 'Team settings', 'Audit log'], $this->sections());

        $this->client->getCookieJar()->clear();
        $this->logIn('bob@example.com');
        $this->client->request('GET', '/teams/'.$id);
        self::assertSame(['Overview', 'Projects', 'Team members', 'Hosts', 'Agents', 'Enrollment'], $this->sections(), 'A TEAM_MEMBER has no Settings section.');
        $this->client->request('GET', '/teams/'.$id.'/settings');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'security.access_denied'), 'The refusal is audited once.');
    }

    #[Test]
    public function it_should_hide_every_tab_of_another_team(): void
    {
        $id = $this->team()->id()->toString();
        Accounts::create(self::getContainer(), 'eve@example.com');
        $this->logIn('eve@example.com');

        foreach (['', '/projects', '/members', '/settings', '/projects/new', '/members/invite'] as $suffix) {
            $this->client->request('GET', '/teams/'.$id.$suffix, server: ['HTTP_TURBO_FRAME' => 'create-project-form']);
            self::assertResponseStatusCodeSame(404, $suffix);
        }
    }

    #[Test]
    public function it_should_show_the_key_facts_and_the_quota_banners_on_the_overview(): void
    {
        $team = $this->team(static function (Team $team): void {
            $team->changeQuotas(QuotaInput::teamQuotas([...array_fill_keys(array_map(static fn (QuotaName $n): string => $n->value, QuotaName::cases()), null), QuotaName::MaxMembers->value => 2, QuotaName::MaxProjects->value => 5]));
        });
        $id = $team->id()->toString();
        Seed::project(self::getContainer(), $team, 'Shop');
        Seed::project(self::getContainer(), $team, 'Vault');
        Seed::project(self::getContainer(), $team, 'Docs');
        Seed::project(self::getContainer(), $team, 'Blog');
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', '/teams/'.$id);
        $facts = array_combine($crawler->filter('dl dt')->each(static fn ($n): string => $n->text()), $crawler->filter('dl dd')->each(static fn ($n): string => $n->text()));
        self::assertSame(['2', '4', '0', 'platform'], [$facts['Members'], $facts['Projects'], $facts['Pending invitations'], $facts['Slug']]);
        self::assertSelectorExists(\sprintf('turbo-frame#team-quotas[src="/teams/%s/frames/quotas"]', $id));
        self::assertSelectorExists(\sprintf('turbo-frame#create-project-form[src="/teams/%s/projects/new"]', $id), 'Shortcut: New project.');
        self::assertSelectorExists(\sprintf('turbo-frame#invite-member-form[src="/teams/%s/members/invite"]', $id), 'Shortcut: Invite member.');

        $this->client->request('GET', \sprintf('/teams/%s/frames/quotas', $id), server: ['HTTP_TURBO_FRAME' => 'team-quotas']);
        self::assertSelectorTextContains('[data-quota-banner="max_members"]', 'the limit of 2 is reached');
        self::assertSelectorTextContains('[data-quota-banner="max_projects"]', '4 of 5 used');
        self::assertSelectorExists('meter.quota-meter[data-state="reached"]');
        self::assertSelectorExists('meter.quota-meter[data-state="warning"]');
    }

    #[Test]
    public function it_should_create_a_project_and_invite_a_member_from_their_dialogs(): void
    {
        $id = $this->team()->id()->toString();
        $this->logIn('alice@example.com');

        $this->client->request('GET', \sprintf('/teams/%s/projects/new', $id));
        self::assertResponseRedirects(\sprintf('/teams/%s/projects', $id), 303, 'A dialog endpoint is never a page.');

        $this->client->request('GET', '/teams/'.$id.'/projects');
        self::assertSelectorExists('header turbo-frame#create-project-form', 'The primary action of the Projects tab.');
        $frame = ['HTTP_TURBO_FRAME' => 'create-project-form'];
        $form = $this->client->request('GET', \sprintf('/teams/%s/projects/new', $id), server: $frame)->filter('turbo-frame#create-project-form form')->form(['project[name]' => '']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#create-project-form form');

        $form = $this->client->request('GET', \sprintf('/teams/%s/projects/new', $id), server: $frame)->filter('turbo-frame#create-project-form form')->form(['project[name]' => 'Shop']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(1, DataTables::draw($this->client, '/teams/'.$id.'/projects', 'projects')['recordsTotal']);

        $this->client->request('GET', '/teams/'.$id.'/members');
        self::assertSelectorExists('header turbo-frame#invite-member-form', 'The primary action of the Members tab.');
        self::assertSelectorExists('[data-datatable-name-value="invitations"]', 'An admin sees the pending invitations.');
        $frame = ['HTTP_TURBO_FRAME' => 'invite-member-form'];
        $form = $this->client->request('GET', \sprintf('/teams/%s/members/invite', $id), server: $frame)->filter('turbo-frame#invite-member-form form')->form(['invite[email]' => 'john@example.com', 'invite[role]' => 'team_viewer']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertSame(1, DataTables::draw($this->client, '/teams/'.$id.'/members', 'invitations')['recordsTotal']);
    }

    #[Test]
    public function it_should_serve_the_dialogs_only_to_whom_may_submit_them(): void
    {
        $id = $this->team()->id()->toString();
        $viewer = Accounts::create(self::getContainer(), 'vic@example.com');
        $team = Accounts::manager(self::getContainer())->find(Team::class, $id);
        self::assertInstanceOf(Team::class, $team);
        $team->admit(Seed::uuid($viewer), TeamRole::Viewer, new \DateTimeImmutable());
        Accounts::manager(self::getContainer())->flush();
        $this->logIn('vic@example.com');

        foreach (['/projects/new' => 'create-project-form', '/members/invite' => 'invite-member-form'] as $suffix => $frame) {
            $this->client->request('GET', '/teams/'.$id.$suffix, server: ['HTTP_TURBO_FRAME' => $frame]);
            self::assertResponseStatusCodeSame(403, $suffix);
        }
        $this->client->request('GET', '/teams/'.$id.'/projects');
        self::assertSelectorNotExists('turbo-frame#create-project-form', 'No "New project" for a TEAM_VIEWER.');
    }

    #[Test]
    public function it_should_rename_the_team_from_its_settings(): void
    {
        $id = $this->team()->id()->toString();
        $this->logIn('alice@example.com');

        $form = $this->client->request('GET', '/teams/'.$id.'/settings')->filter(\sprintf('form[action="/teams/%s/rename"]', $id))->form(['team_name[name]' => 'Omega']);
        self::assertSelectorTextContains('dd.font-mono', 'platform');
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertSame('Omega', $this->fetchString('SELECT name FROM organization_team'));
    }

    #[Test]
    public function it_should_mark_the_browsed_team_in_the_team_switcher(): void
    {
        $team = $this->team();
        $alice = Accounts::manager(self::getContainer())->getRepository(User::class)->findOneBy(['email.value' => 'alice@example.com']);
        self::assertInstanceOf(User::class, $alice);
        $other = Seed::team(self::getContainer(), 'Payments', [[$alice, TeamRole::Admin]]);
        $this->logIn('alice@example.com');

        foreach ([$team, $other] as $browsed) {
            $crawler = $this->client->request('GET', '/teams/'.$browsed->id()->toString());
            $current = $crawler->filter('#dropdown-menu-team-switcher-content [role="menuitem"][aria-current="page"]');
            self::assertCount(1, $current);
            self::assertSame('/teams/'.$browsed->id()->toString(), $current->attr('href'));
        }
    }

    /**
     * The team sections of the sidebar (its first menu).
     *
     * @return list<string>
     */
    private function sections(): array
    {
        return $this->client->getCrawler()->filter('[data-slot="sidebar"] [data-slot="sidebar-group"]')->first()->filter('a')->each(static fn ($a): string => $a->text());
    }

    /**
     * Alpha: Alice TEAM_ADMIN, Bob TEAM_MEMBER.
     *
     * @param ?\Closure(Team): void $tune
     */
    private function team(?\Closure $tune = null): Team
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $team = Seed::team(self::getContainer(), 'Platform', [[$alice, TeamRole::Admin], [$bob, TeamRole::Member]]);
        if (null !== $tune) {
            $tune($team);
            Accounts::manager(self::getContainer())->flush();
        }

        return $team;
    }
}
