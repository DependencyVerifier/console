<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Environment of a project (entity of the Project aggregate). Unique name per project, case-insensitive.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_environment')]
class Environment
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'environments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,
        #[ORM\Column(length: 60)]
        private string $name,
        #[ORM\Column]
        private bool $production,
    ) {
    }

    public function id(): EnvironmentId
    {
        return EnvironmentId::of($this->id);
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    public function update(string $name, bool $production): void
    {
        $this->name = $name;
        $this->production = $production;
    }
}
