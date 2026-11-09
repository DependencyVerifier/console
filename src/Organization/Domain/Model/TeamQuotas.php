<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * The 9 quotas of a team (or the instance defaults, copied at team creation). A null column is unlimited.
 * Deviation from the 4-attribute rule, recorded in CLAUDE.md: one column per quota.
 */
#[ORM\Embeddable]
final class TeamQuotas
{
    #[ORM\Column(nullable: true)]
    private ?int $maxProjects = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxEnvironmentsPerProject = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxAgents = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxMembers = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxNotificationOnlyAccounts = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxNotificationChannels = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxReusableEnrollmentTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $snapshotRetentionDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $minScanIntervalMinutes = null;

    private function __construct()
    {
    }

    public static function defaults(): self
    {
        $quotas = new self();
        foreach (QuotaName::cases() as $name) {
            $quotas->set($name, Quota::of($name->defaultValue()));
        }

        return $quotas;
    }

    /**
     * @param array<value-of<QuotaName>, Quota> $quotas every quota, keyed by name
     */
    public static function of(array $quotas): self
    {
        $result = new self();
        foreach (QuotaName::cases() as $name) {
            $result->set($name, $quotas[$name->value] ?? throw new \InvalidArgumentException(\sprintf('Quota "%s" is missing.', $name->value)));
        }

        return $result;
    }

    public function get(QuotaName $name): Quota
    {
        return Quota::fromStorage(match ($name) {
            QuotaName::MaxProjects => $this->maxProjects,
            QuotaName::MaxEnvironmentsPerProject => $this->maxEnvironmentsPerProject,
            QuotaName::MaxAgents => $this->maxAgents,
            QuotaName::MaxMembers => $this->maxMembers,
            QuotaName::MaxNotificationOnlyAccounts => $this->maxNotificationOnlyAccounts,
            QuotaName::MaxNotificationChannels => $this->maxNotificationChannels,
            QuotaName::MaxReusableEnrollmentTokens => $this->maxReusableEnrollmentTokens,
            QuotaName::SnapshotRetentionDays => $this->snapshotRetentionDays,
            QuotaName::MinScanIntervalMinutes => $this->minScanIntervalMinutes,
        });
    }

    /**
     * @return array<value-of<QuotaName>, Quota>
     */
    public function all(): array
    {
        $all = [];
        foreach (QuotaName::cases() as $name) {
            $all[$name->value] = $this->get($name);
        }

        return $all;
    }

    private function set(QuotaName $name, Quota $quota): void
    {
        $value = $quota->toStorage();
        match ($name) {
            QuotaName::MaxProjects => $this->maxProjects = $value,
            QuotaName::MaxEnvironmentsPerProject => $this->maxEnvironmentsPerProject = $value,
            QuotaName::MaxAgents => $this->maxAgents = $value,
            QuotaName::MaxMembers => $this->maxMembers = $value,
            QuotaName::MaxNotificationOnlyAccounts => $this->maxNotificationOnlyAccounts = $value,
            QuotaName::MaxNotificationChannels => $this->maxNotificationChannels = $value,
            QuotaName::MaxReusableEnrollmentTokens => $this->maxReusableEnrollmentTokens = $value,
            QuotaName::SnapshotRetentionDays => $this->snapshotRetentionDays = $value,
            QuotaName::MinScanIntervalMinutes => $this->minScanIntervalMinutes = $value,
        };
    }
}
