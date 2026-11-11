<?php

declare(strict_types=1);

namespace Tests\Audit\Functional;

use Audit\Application\Query\AuditLog\AuditCursor;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\IdentityWebTestCase;

/**
 * The audit pages: /admin/audit for the super admin, /teams/{id}/audit for whoever holds `team.audit.view` on that
 * team; newest first, filtered by outcome, a validated cursor for older entries.
 */
final class AuditPagesTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_show_the_team_audit_to_its_admin_only_and_scoped_to_the_team(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $alpha = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$bob, TeamRole::Member]]);
        $beta = Seed::team(self::getContainer(), 'Beta', [[$bob, TeamRole::Admin]]);
        [$a, $b] = [$alpha->id()->toString(), $beta->id()->toString()];
        $this->logIn('alice@example.com');
        $this->rename($a, 'Alpha 2');
        $this->client->getCookieJar()->clear();
        $this->logIn('bob@example.com');
        $this->rename($b, 'Beta 2');

        $this->client->request('GET', '/teams/'.$a.'/audit');
        self::assertResponseStatusCodeSame(403, 'A TEAM_MEMBER has no team.audit.view.');

        $this->client->getCookieJar()->clear();
        $this->logIn('alice@example.com');
        $crawler = $this->client->request('GET', '/teams/'.$a.'/audit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', 'Audit log');
        $actions = $crawler->filter('turbo-frame#audit tbody tr td:nth-child(3)')->each(static fn ($n): string => $n->text());
        self::assertCount(1, array_filter($actions, static fn (string $action): bool => 'organization.rename_team' === $action), 'Its own rename, never the other team\'s.');
        self::assertSelectorTextContains('turbo-frame#audit tbody', 'alice@example.com', 'The actor shown by email.');

        $this->client->request('GET', '/teams/'.$b.'/audit');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_filter_the_global_audit_by_outcome_and_validate_its_cursor(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');
        $this->client->request('POST', '/admin/teams/new', ['team' => ['name' => 'X', 'firstAdminEmail' => 'x@example.com']], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(403, 'A forged submission: one denied entry.');

        $crawler = $this->client->request('GET', '/admin/audit?outcome=denied');
        self::assertResponseIsSuccessful();
        $outcomes = array_values(array_unique($crawler->filter('turbo-frame#audit tbody tr td:nth-child(5) [data-slot="badge"]')->each(static fn ($n): string => $n->text())));
        self::assertSame(['Denied'], $outcomes);
        self::assertSelectorTextSame('turbo-frame#audit nav a[aria-current="page"]', 'Denied');
        self::assertSelectorNotExists('turbo-frame#audit a[href*="cursor="]', 'Fewer entries than a page: no older link.');

        $this->client->request('GET', '/admin/audit?cursor='.AuditCursor::at(new \DateTimeImmutable('2000-01-01'), '019a6b2c-1f00-7a11-8b22-3c44d55e6f01')->encode());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#audit', 'No older entry.');

        $this->client->request('GET', '/admin/audit?cursor=not-a-cursor');
        self::assertResponseStatusCodeSame(422);

        $this->client->getCookieJar()->clear();
        Accounts::create(self::getContainer(), 'jane@example.com');
        $this->logIn('jane@example.com');
        $this->client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(403);
    }

    private function rename(string $teamId, string $name): void
    {
        $form = $this->client->request('GET', \sprintf('/teams/%s/settings', $teamId))->filter(\sprintf('form[action="/teams/%s/rename"]', $teamId))->form(['team_name[name]' => $name]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
    }
}
