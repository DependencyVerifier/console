<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Fleet\Domain\Event\HostLabelled;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * A machine of a team, created or found at enrollment from its identity (HostFacts::identityFor()). Its agents point
 * to it. Its facts are replaced at each enrollment (refresh) and merged with what each inventory observes (observe).
 * A human may give it a display name and its IP addresses. An identity found from the hostname alone is promoted to
 * its Docker engine once an inventory tells it (promoteToDockerEngine), so a later enrollment finds the same host.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_host')]
#[ORM\UniqueConstraint(name: 'fleet_host_identity', columns: ['team_id', 'identity_kind', 'identity_value'])]
class Host implements RecordsEvents
{
    use EventRecording;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: HostLabel::MAX_NAME, nullable: true)]
    private ?string $displayName = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $addresses = [];

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Embedded(class: HostIdentity::class, columnPrefix: false)]
        private HostIdentity $identity,
        #[ORM\Embedded(class: HostFacts::class, columnPrefix: false)]
        private HostFacts $facts,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function register(HostId $id, Uuid $teamId, HostIdentity $identity, HostFacts $facts, \DateTimeImmutable $now): self
    {
        return new self($id->value, $teamId, $identity, $facts, $now);
    }

    public function id(): HostId
    {
        return HostId::of($this->id);
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function identity(): HostIdentity
    {
        return $this->identity;
    }

    public function facts(): HostFacts
    {
        return $this->facts;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * An enrollment: its facts replace these ones, and the host is seen.
     */
    public function refresh(HostFacts $facts, \DateTimeImmutable $now): void
    {
        $this->facts = $facts;
        $this->seen($now);
    }

    /**
     * What an inventory observed: merged over the current facts (HostFacts::mergedWith). Neither the last contact
     * (the polls tell it) nor the display name (a human gives it) changes, and nothing is published.
     *
     * @throws InvalidHostFacts
     */
    public function observe(
        ?string $hostname = null,
        ?string $osId = null,
        ?string $osVersionId = null,
        ?string $osPrettyName = null,
        ?string $kernel = null,
        ?string $arch = null,
        ?string $dockerEngineId = null,
    ): void {
        $this->facts = $this->facts->mergedWith(
            hostname: $hostname,
            osId: $osId,
            osVersionId: $osVersionId,
            osPrettyName: $osPrettyName,
            kernel: $kernel,
            arch: $arch,
            dockerEngineId: $dockerEngineId,
        );
    }

    /**
     * The Docker identity this host could take: only a host found from its hostname alone, once its facts carry a
     * Docker engine id. Whether another host of the team already holds it is for the caller to check.
     */
    public function dockerIdentityCandidate(): ?HostIdentity
    {
        $engineId = $this->facts->dockerEngineId;
        if (!$this->identity->isHostname() || null === $engineId || '' === $engineId) {
            return null;
        }

        return HostIdentity::docker($engineId);
    }

    /**
     * Takes its Docker identity (dockerIdentityCandidate). False when it has none: a machine, Docker or embedded
     * identity never changes.
     */
    public function promoteToDockerEngine(): bool
    {
        $candidate = $this->dockerIdentityCandidate();
        if (null === $candidate) {
            return false;
        }

        $this->identity = $candidate;

        return true;
    }

    public function seen(\DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
    }

    public function label(): HostLabel
    {
        return new HostLabel($this->displayName, $this->addresses);
    }

    /**
     * Its display name, else its hostname.
     */
    public function name(): string
    {
        return $this->displayName ?? $this->facts->hostname;
    }

    public function relabel(HostLabel $label): void
    {
        if ($label->displayName === $this->displayName && $label->addresses === $this->addresses) {
            return;
        }

        $this->displayName = $label->displayName;
        $this->addresses = $label->addresses;
        $this->recordThat(new HostLabelled($this->id->toRfc4122(), $this->teamId->toRfc4122(), $label->displayName, $label->addresses));
    }
}
