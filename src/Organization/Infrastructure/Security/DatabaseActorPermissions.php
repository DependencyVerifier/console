<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Authorization\EffectivePermissions;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\ExtraPermissions;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Shared\Application\Security\CurrentActor;

/**
 * Active memberships of the actor, read from the database at every call (two small queries): a role changed in this
 * very request is seen by the next check, and nothing outlives the request in the worker. The super admin flag comes
 * from the authenticated account (fully authenticated only).
 */
final readonly class DatabaseActorPermissions implements ActorPermissions
{
    public function __construct(private CurrentActor $currentActor, private Connection $connection)
    {
    }

    public function current(): EffectivePermissions
    {
        $actor = $this->currentActor->actor();
        if (!$actor->isUser()) {
            return EffectivePermissions::resolve(false, [], []);
        }

        $userId = $actor->userId()->toRfc4122();

        /** @var list<array{team_id: string, role: string, extra_permissions: string}> $teams */
        $teams = $this->connection->fetchAllAssociative(
            "SELECT team_id, role, extra_permissions FROM organization_team_membership WHERE user_id = :user AND status = 'active'",
            ['user' => $userId],
        );
        /** @var list<array{project_id: string, team_id: string, role: string, extra_permissions: string}> $projects */
        $projects = $this->connection->fetchAllAssociative(
            "SELECT m.project_id, p.team_id, m.role, m.extra_permissions FROM organization_project_membership m
             JOIN organization_project p ON p.id = m.project_id WHERE m.user_id = :user AND m.status = 'active'",
            ['user' => $userId],
        );

        return EffectivePermissions::resolve(
            $this->currentActor->isSuperAdmin(),
            array_map(static fn (array $row): TeamGrant => new TeamGrant($row['team_id'], TeamRole::from($row['role']), ExtraPermissions::decode(self::list($row['extra_permissions']))), $teams),
            array_map(static fn (array $row): ProjectGrant => new ProjectGrant($row['project_id'], $row['team_id'], ProjectRole::from($row['role']), ExtraPermissions::decode(self::list($row['extra_permissions']))), $projects),
        );
    }

    /**
     * @return list<string>
     */
    private static function list(string $json): array
    {
        $values = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);

        return \is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
