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

use function Symfony\Component\String\u;

/**
 * "Re-sync memberships" (/admin/users row menu) replays the cascade of an account status that failed: memberships
 * follow the account again.
 */
final class ResyncMembershipsTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_make_the_memberships_follow_the_account_status_again(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $jane = Accounts::create(self::getContainer(), 'jane@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$jane, TeamRole::Member]]);
        $janeId = $jane->id()->toString();
        // A cascade that failed: the account is active, its membership stayed suspended.
        $this->connection()->executeStatement("UPDATE organization_team_membership SET status = 'suspended'");
        $this->logIn('root@example.com');

        $this->resync($janeId);
        self::assertSame('active', $this->fetchString('SELECT status FROM organization_team_membership WHERE team_id = ?', [$team->id()->toString()]));
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.restore_memberships'));

        // The other way: the account is deactivated, its membership stayed active.
        $this->connection()->executeStatement("UPDATE identity_user SET status = 'disabled' WHERE id = ?", [$janeId]);
        $this->resync($janeId);
        self::assertSame('suspended', $this->fetchString('SELECT status FROM organization_team_membership'));
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.suspend_memberships'));
    }

    #[Test]
    public function it_should_refuse_a_forged_token_and_an_unknown_account(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $this->client->request('POST', '/admin/users/019a6b2c-1f00-7a11-8b22-3c44d55e6f09/resync-memberships', ['action' => ['_token' => 'any']], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(404);

        $janeId = Accounts::create(self::getContainer(), 'jane@example.com')->id()->toString();
        $this->client->request('POST', \sprintf('/admin/users/%s/resync-memberships', $janeId), ['action' => ['_token' => 'forged']], server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'csrf.rejected'));
    }

    private function resync(string $userId): void
    {
        $rows = DataTables::draw($this->client, '/admin/users', 'users')['data'];
        $row = array_first(array_filter($rows, static fn (array $r): bool => u($r['email'])->containsAny('jane@example.com')));
        self::assertNotNull($row);
        $form = DataTables::cell($row['actions'])->filter(\sprintf('form[action="/admin/users/%s/resync-memberships"]', $userId))->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
    }
}
