<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * The project page: key facts, environments and members with their creation dialogs, settings with a confirmed
 * deletion. Its dialogs are served only to whom may submit them.
 */
final class ProjectPageTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_show_the_key_facts_and_the_dialogs_of_an_admin(): void
    {
        [$teamId, $projectId] = $this->project();
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', \sprintf('/teams/%s/projects/%s', $teamId, $projectId));
        self::assertResponseIsSuccessful();
        $facts = array_combine($crawler->filter('dl dt')->each(static fn ($n): string => $n->text()), $crawler->filter('dl dd')->each(static fn ($n): string => $n->text()));
        self::assertSame(['0', '3 per project, set by the team', '1', 'shop'], [$facts['Environments of this project'], $facts['Environment limit'], $facts['Project members'], $facts['Slug']], 'The count is the project\'s, the limit comes from the team.');
        self::assertSelectorExists(\sprintf('turbo-frame#create-environment-form[src="/teams/%s/projects/%s/environments/new"]', $teamId, $projectId));
        self::assertSelectorExists(\sprintf('turbo-frame#invite-project-member-form[src="/teams/%s/projects/%s/members/invite"]', $teamId, $projectId));

        $base = \sprintf('/teams/%s/projects/%s', $teamId, $projectId);
        foreach (['' => 'Overview', '/environments' => 'Environments', '/members' => 'Project members', '/settings' => 'Project settings'] as $suffix => $label) {
            $this->client->request('GET', $base.$suffix);
            self::assertResponseIsSuccessful($label);
            self::assertSelectorTextSame('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', $label, 'The section is picked in the project group of the sidebar.');
        }
        self::assertSelectorTextContains('[data-slot="sidebar"]', 'Project · Shop');
        self::assertSelectorExists(\sprintf('dialog#dialog-delete-project form[action="%s/delete"]', $base), 'The deletion waits behind its confirmation, in Settings.');
        self::assertSame(1, DataTables::draw($this->client, $base.'/members', 'project_members')['recordsTotal']);
        self::assertSame(0, DataTables::draw($this->client, $base.'/environments', 'environments')['recordsTotal']);
    }

    #[Test]
    public function it_should_serve_the_dialogs_only_to_whom_may_submit_them(): void
    {
        [$teamId, $projectId] = $this->project();
        $this->logIn('bob@example.com');

        $this->client->request('GET', \sprintf('/teams/%s/projects/%s', $teamId, $projectId));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#create-environment-form');
        self::assertSelectorNotExists(\sprintf('a[href="/teams/%s/projects/%s/settings"]', $teamId, $projectId), 'No Settings section for a PROJECT_VIEWER.');
        $this->client->request('GET', \sprintf('/teams/%s/projects/%s/settings', $teamId, $projectId));
        self::assertResponseStatusCodeSame(403);

        foreach (['/environments/new' => 'create-environment-form', '/members/invite' => 'invite-project-member-form'] as $suffix => $frame) {
            $this->client->request('GET', \sprintf('/teams/%s/projects/%s%s', $teamId, $projectId, $suffix), server: ['HTTP_TURBO_FRAME' => $frame]);
            self::assertResponseStatusCodeSame(403, $suffix);
        }

        $other = Seed::team(self::getContainer(), 'Beta', [])->id()->toString();
        $this->client->request('GET', \sprintf('/teams/%s/projects/%s/environments/new', $other, $projectId), server: ['HTTP_TURBO_FRAME' => 'create-environment-form']);
        self::assertResponseStatusCodeSame(404, 'The project must belong to the team of the URL.');
    }

    #[Test]
    public function it_should_disable_add_environment_once_the_team_limit_is_reached(): void
    {
        [$teamId, $projectId] = $this->project();
        $this->logIn('alice@example.com');
        $base = \sprintf('/teams/%s/projects/%s', $teamId, $projectId);
        $frame = ['HTTP_TURBO_FRAME' => 'create-environment-form'];

        foreach (['prod', 'staging', 'dev'] as $name) {
            $this->client->request('GET', $base.'/environments');
            self::assertSelectorNotExists('header button[data-dialog-trigger-for="create-environment"][disabled]', $name);
            $form = $this->client->request('GET', $base.'/environments/new', server: $frame)->filter('turbo-frame#create-environment-form form')->form(['environment[name]' => $name]);
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html', ...$frame]);
            self::assertResponseIsSuccessful();
            self::assertSame('<turbo-stream action="refresh"></turbo-stream>', (string) $this->client->getResponse()->getContent(), 'The page refreshes: its counters and buttons follow the new usage.');
        }

        $this->client->request('GET', $base.'/environments');
        self::assertSelectorTextContains('#action-alerts, [role="status"], main', 'Environment added.');
        self::assertSelectorExists('header button[data-dialog-trigger-for="create-environment"][disabled]', 'Limit of 3 per project reached.');
        self::assertSelectorTextContains('header [id="create-environment-disabled-reason"]', 'the team\'s limit is reached');
    }

    /**
     * Alpha: Alice TEAM_ADMIN; Bob TEAM_VIEWER and PROJECT_VIEWER of Shop.
     *
     * @return array{string, string}
     */
    private function project(): array
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$bob, TeamRole::Viewer]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop', [[$bob, ProjectRole::Viewer]]);

        return [$team->id()->toString(), $project->id()->toString()];
    }
}
