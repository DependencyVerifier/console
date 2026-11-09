<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What an invitation grants: a team role, or a project role (the account also becomes TEAM_GUEST when it is not a
 * member of the team yet, decision 9).
 */
#[ORM\Embeddable]
final class InvitationTarget
{
    private const string TEAM = 'team';
    private const string PROJECT = 'project';

    #[ORM\Column(name: 'team_id', type: 'uuid')]
    private Uuid $teamId;

    #[ORM\Column(name: 'project_id', type: 'uuid', nullable: true)]
    private ?Uuid $projectId;

    private function __construct(#[ORM\Column(name: 'target_type', length: 16)]
        private string $type, TeamId $teamId, ?ProjectId $projectId, #[ORM\Column(name: 'role', length: 16)]
        private string $role)
    {
        $this->teamId = $teamId->value;
        $this->projectId = $projectId?->value;
    }

    public static function team(TeamId $teamId, TeamRole $role): self
    {
        return new self(self::TEAM, $teamId, null, $role->value);
    }

    public static function project(TeamId $teamId, ProjectId $projectId, ProjectRole $role): self
    {
        return new self(self::PROJECT, $teamId, $projectId, $role->value);
    }

    public function isProject(): bool
    {
        return self::PROJECT === $this->type;
    }

    public function teamId(): TeamId
    {
        return TeamId::of($this->teamId);
    }

    public function projectId(): ProjectId
    {
        return ProjectId::of($this->projectId ?? throw new \LogicException('A team invitation has no project.'));
    }

    public function teamRole(): TeamRole
    {
        return $this->isProject() ? throw new \LogicException('A project invitation has no team role.') : TeamRole::from($this->role);
    }

    public function projectRole(): ProjectRole
    {
        return $this->isProject() ? ProjectRole::from($this->role) : throw new \LogicException('A team invitation has no project role.');
    }

    public function roleValue(): string
    {
        return $this->role;
    }

    /**
     * Identity of the target for the "one pending invitation per email and target" rule.
     */
    public function key(): string
    {
        return $this->type.':'.($this->isProject() ? $this->projectId()->toString() : $this->teamId()->toString());
    }
}
