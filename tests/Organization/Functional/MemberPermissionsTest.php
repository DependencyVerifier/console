<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * The "Permissions" dialog of a member row: the ○ permissions of the member's role as checkboxes, saved at once.
 */
final class MemberPermissionsTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_change_the_team_extras_of_a_member_from_their_row(): void
    {
        [$teamId, $bobId] = $this->team();
        $this->logIn('alice@example.com');

        $rows = DataTables::draw($this->client, '/teams/'.$teamId.'/members', 'team_members')['data'];
        $bob = array_first(array_filter($rows, static fn (array $r): bool => u($r['email'])->containsAny('bob@example.com')));
        $alice = array_first(array_filter($rows, static fn (array $r): bool => u($r['email'])->containsAny('alice@example.com')));
        self::assertNotNull($bob);
        self::assertNotNull($alice);
        $url = \sprintf('/teams/%s/members/%s/permissions', $teamId, $bobId);
        self::assertSame($url, DataTables::cell($bob['actions'])->filter(\sprintf('turbo-frame#member-permissions-%s-form', $bobId))->attr('src'));
        self::assertCount(0, DataTables::cell($alice['actions'])->filter('turbo-frame'), 'A TEAM_ADMIN has nothing to receive on top.');

        // The row menu: every action is a form whose submit button is the menu item itself, the current role is
        // checked and not submittable, and never a button inside a button or a link.
        $menu = DataTables::cell($bob['actions']);
        self::assertCount(1, $menu->filter('[data-slot="dropdown-menu-trigger"][aria-label="Actions for bob@example.com"]'));
        self::assertCount(1, $menu->filter(\sprintf('form[action="/teams/%s/members/%s/remove"] button[type="submit"][role="menuitem"]', $teamId, $bobId)));
        self::assertCount(3, $menu->filter(\sprintf('form[action="/teams/%s/members/%s/role"] button[type="submit"][role="menuitem"]', $teamId, $bobId)), 'One form per other role.');
        self::assertSame('Team member', u($menu->filter('[role="menuitem"][aria-checked="true"]')->text())->trim()->toString());
        self::assertCount(0, $menu->filter('button button, a button, button a'));

        $frame = ['HTTP_TURBO_FRAME' => \sprintf('member-permissions-%s-form', $bobId)];
        $crawler = $this->client->request('GET', $url, server: $frame);
        self::assertResponseIsSuccessful();
        self::assertCount(13, $crawler->filter('input[type="checkbox"][name="extra[permissions][]"]'), 'The ○ of TEAM_MEMBER.');
        self::assertSelectorTextContains('label[for="extra_permissions_0"]', 'View the team audit log');

        $form = $crawler->filter('turbo-frame form')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['extra'] ?? null);
        $values['extra']['permissions'] = ['host.enroll', 'project.create'];
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['host.enroll', 'project.create'], json_decode($this->fetchString('SELECT extra_permissions FROM organization_team_membership WHERE user_id = ?', [$bobId]), true));
        self::assertSame(2, $this->fetchInt("SELECT COUNT(*) FROM organization_permission_grant WHERE change = 'extra_granted'"));
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.change_extra_permissions'));

        $crawler = $this->client->request('GET', $url, server: $frame);
        self::assertSame(['host.enroll', 'project.create'], $crawler->filter('input[type="checkbox"]:checked')->each(static fn ($i): string => (string) $i->attr('value')), 'The dialog shows the saved state.');
    }

    #[Test]
    public function it_should_change_the_project_extras_of_a_member(): void
    {
        [$teamId, $bobId, $projectId] = $this->team(withProject: true);
        $this->logIn('alice@example.com');

        $url = \sprintf('/teams/%s/projects/%s/members/%s/permissions', $teamId, $projectId, $bobId);
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('member-permissions-%s-form', $bobId)];
        $form = $this->client->request('GET', $url, server: $frame)->filter('turbo-frame form')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['extra'] ?? null);
        $values['extra']['permissions'] = ['project.scan_now'];
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseIsSuccessful();
        self::assertSame(['project.scan_now'], json_decode($this->fetchString('SELECT extra_permissions FROM organization_project_membership WHERE user_id = ?', [$bobId]), true));
    }

    #[Test]
    public function it_should_serve_the_dialog_to_who_manages_the_members_only(): void
    {
        [$teamId, $bobId] = $this->team();
        $this->logIn('bob@example.com');

        $this->client->request('GET', \sprintf('/teams/%s/members/%s/permissions', $teamId, $bobId), server: ['HTTP_TURBO_FRAME' => \sprintf('member-permissions-%s-form', $bobId)]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Alpha: Alice TEAM_ADMIN, Bob TEAM_MEMBER (and PROJECT_VIEWER of Shop when asked).
     *
     * @return array{string, string, string}
     */
    private function team(bool $withProject = false): array
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$bob, TeamRole::Member]]);
        $projectId = $withProject ? Seed::project(self::getContainer(), $team, 'Shop', [[$bob, ProjectRole::Viewer]])->id()->toString() : '';

        return [$team->id()->toString(), $bob->id()->toString(), $projectId];
    }
}
