<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Instance settings singleton: the default team quotas, copied when a team is created (changing them never touches
 * the existing teams).
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization_instance_settings')]
class InstanceSettings
{
    public const string ID = 'instance';

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $id = self::ID;

    #[ORM\Embedded(class: TeamQuotas::class, columnPrefix: 'default_')]
    private TeamQuotas $defaultQuotas;

    private function __construct()
    {
        $this->defaultQuotas = TeamQuotas::defaults();
    }

    public static function initial(): self
    {
        return new self();
    }

    public function defaultQuotas(): TeamQuotas
    {
        return $this->defaultQuotas;
    }

    public function changeDefaultQuotas(TeamQuotas $quotas): void
    {
        $this->defaultQuotas = $quotas;
    }
}
