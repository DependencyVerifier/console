<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\String\u;

/**
 * The short host facts sent at enrollment (protocol §5.1 `host`): display and grouping only, never trusted for
 * authorization. Bounded so a hostile agent cannot store anything large.
 */
#[ORM\Embeddable]
final readonly class HostFacts
{
    private const int MAX = 255;

    public function __construct(
        #[ORM\Column(length: 255)]
        public string $hostname,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $osId = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $osVersionId = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $osPrettyName = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $kernel = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $arch = null,
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $dockerEngineId = null,
        #[ORM\Column(length: 64, nullable: true)]
        public ?string $machineIdHash = null,
    ) {
        if ('' === u($hostname)->trim()->toString()) {
            throw new InvalidEnrollmentPayload();
        }

        foreach ([$hostname, $osId, $osVersionId, $osPrettyName, $kernel, $arch, $dockerEngineId] as $value) {
            if (null !== $value && u($value)->length() > self::MAX) {
                throw new InvalidEnrollmentPayload();
            }
        }

        if (null !== $machineIdHash && [] === u($machineIdHash)->match('/^[0-9a-f]{64}$/')) {
            throw new InvalidEnrollmentPayload();
        }
    }

    /**
     * The key grouping the agents of one machine within a team (decision of step 2): the Docker engine for a
     * container agent, the machine id for a host agent, each falling back on the other, then on the hostname. An
     * embedded agent is its own host (one per replica; the replicas of an image share a machine id and a hostname
     * may be reused): the fingerprint of its key.
     */
    public function identityFor(AgentMode $mode, string $keyFingerprint): HostIdentity
    {
        if (AgentMode::Embedded === $mode) {
            return new HostIdentity('embedded', $keyFingerprint);
        }

        $preferred = AgentMode::Container === $mode
            ? [['docker', $this->dockerEngineId], ['machine', $this->machineIdHash]]
            : [['machine', $this->machineIdHash], ['docker', $this->dockerEngineId]];
        foreach ($preferred as [$kind, $value]) {
            if (null !== $value && '' !== $value) {
                return new HostIdentity($kind, $value);
            }
        }

        return new HostIdentity('hostname', u($this->hostname)->lower()->toString());
    }
}
