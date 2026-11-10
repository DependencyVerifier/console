<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * The "Delete" button of a row of /admin/teams: a plain confirmation dialog, then the team goes with everything it
 * holds (decision 16), its pending invitations revoked.
 */
final class AdminTeamDeleteTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_delete_a_team_with_its_projects_after_a_confirmation(): void
    {
        $root = Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $team = Seed::team(self::getContainer(), 'Platform', [[$jane, TeamRole::Admin]]);
        $kept = Seed::team(self::getContainer(), 'Payments', [[$jane, TeamRole::Member]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop');
        $project->addEnvironment(EnvironmentId::of(Uuid::v7()), 'prod', true);
        $invitation = Invitation::issue(InvitationId::of(Uuid::v7()), 'john@example.com', InvitationTarget::team($team->id(), TeamRole::Member), Seed::uuid($root), 't', 'h:delete', new \DateTimeImmutable());
        $invitation->releaseEvents();
        Accounts::manager(self::getContainer())->persist($invitation);
        Accounts::manager(self::getContainer())->flush();
        $teamId = $team->id()->toString();
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/teams');
        $row = array_values(array_filter(DataTables::draw($this->client, '/admin/teams', 'teams')['data'], static fn (array $r): bool => u($r['name'])->containsAny('Platform')));
        $cell = DataTables::cell($row[0]['actions']);
        self::assertCount(1, $cell->filter(\sprintf('dialog#dialog-delete-team-%s form[action="/admin/teams/%s/delete"]', $teamId, $teamId)), 'The form waits in its confirmation dialog.');
        $form = $cell->filter(\sprintf('form[action="/admin/teams/%s/delete"]', $teamId))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['Payments'], $this->connection()->fetchFirstColumn('SELECT name FROM organization_team'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_project'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_environment'));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM organization_team_membership'), 'Only the membership in the other team stays.');
        self::assertSame($kept->id()->toString(), $this->fetchString('SELECT team_id FROM organization_team_membership'));
        self::assertSame('revoked', $this->fetchString('SELECT status FROM organization_invitation'));
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.delete_team'));

        $this->client->request('GET', '/teams/'.$teamId);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_refuse_a_forged_token_or_a_non_super_admin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $teamId = Seed::team(self::getContainer(), 'Platform', [[$jane, TeamRole::Admin]])->id()->toString();
        $this->logIn('root@example.com');

        $this->client->request('POST', \sprintf('/admin/teams/%s/delete', $teamId), ['action' => ['_token' => 'forged']], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'csrf.rejected'));

        $this->client->getCookieJar()->clear();
        $this->logIn('jane@example.com');
        $this->client->request('POST', \sprintf('/admin/teams/%s/delete', $teamId), ['action' => ['_token' => 'any']], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM organization_team'));
    }
}
