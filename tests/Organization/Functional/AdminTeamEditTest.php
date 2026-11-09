<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * The "Edit" dialog of each row of /admin/teams: name and quotas in one form (the former quotas page is gone).
 */
final class AdminTeamEditTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_offer_an_edit_dialog_on_each_row(): void
    {
        $teamId = $this->team();
        $actions = DataTables::draw($this->client, '/admin/teams', 'teams')['data'][0]['actions'];

        $cell = DataTables::cell($actions);
        self::assertCount(1, $cell->filter('button[aria-haspopup="dialog"]'));
        self::assertSame(\sprintf('/admin/teams/%s/edit', $teamId), $cell->filter(\sprintf('turbo-frame#edit-team-%s-form', $teamId))->attr('src'));

        $this->client->request('GET', \sprintf('/admin/teams/%s/quotas', $teamId));
        self::assertResponseStatusCodeSame(404, 'The former quotas page is gone.');
    }

    #[Test]
    public function it_should_rename_the_team_and_change_its_quotas(): void
    {
        $teamId = $this->team();
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-team-%s-form', $teamId)];

        $crawler = $this->client->request('GET', \sprintf('/admin/teams/%s/edit', $teamId), server: $frame);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(\sprintf('turbo-frame#edit-team-%s-form form', $teamId))->form();
        self::assertSame('Alpha', $form->getValues()['team_edit[name]'] ?? null, 'Prefilled with the current values.');
        self::assertSame('10', $form->getValues()['team_edit[quotas][max_projects]'] ?? null);

        $form->setValues(['team_edit[name]' => 'Omega', 'team_edit[quotas][max_projects]' => '2', 'team_edit[quotas][max_agents]' => '']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('Omega', $this->fetchString('SELECT name FROM organization_team WHERE id = ?', [$teamId]));
        self::assertSame(2, $this->fetchInt('SELECT quota_max_projects FROM organization_team WHERE id = ?', [$teamId]));
        self::assertNull($this->connection()->fetchOne('SELECT quota_max_agents FROM organization_team WHERE id = ?', [$teamId]), 'Empty: unlimited.');
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.update_team'));
    }

    #[Test]
    public function it_should_keep_an_invalid_edit_in_the_dialog(): void
    {
        $teamId = $this->team();
        $frame = ['HTTP_TURBO_FRAME' => \sprintf('edit-team-%s-form', $teamId)];
        $form = $this->client->request('GET', \sprintf('/admin/teams/%s/edit', $teamId), server: $frame)
            ->filter(\sprintf('turbo-frame#edit-team-%s-form form', $teamId))->form(['team_edit[name]' => '', 'team_edit[quotas][max_projects]' => '-1']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists(\sprintf('turbo-frame#edit-team-%s-form form', $teamId));
        self::assertSame('Alpha', $this->fetchString('SELECT name FROM organization_team WHERE id = ?', [$teamId]));
    }

    #[Test]
    public function it_should_keep_the_edit_dialog_for_the_super_admin(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $teamId = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
        $this->logIn('alice@example.com');

        $this->client->request('GET', \sprintf('/admin/teams/%s/edit', $teamId), server: ['HTTP_TURBO_FRAME' => \sprintf('edit-team-%s-form', $teamId)]);
        self::assertResponseStatusCodeSame(403, 'Even its own TEAM_ADMIN: quotas belong to the super admin.');
    }

    private function team(): string
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $teamId = Seed::team(self::getContainer(), 'Alpha', [])->id()->toString();
        $this->logIn('root@example.com');

        return $teamId;
    }
}
