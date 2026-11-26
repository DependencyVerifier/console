<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\String\u;

/**
 * The short host facts: sent at enrollment (protocol §5.1 `host`), then observed by each inventory (mergedWith).
 * Display and grouping only, never trusted for authorization. Bounded so a hostile agent cannot store anything large,
 * and free of control and format characters (bidi overrides, zero-width marks) so a name cannot fake another one.
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
            throw new InvalidHostFacts('hostname');
        }

        $bounded = ['hostname' => $hostname, 'os_id' => $osId, 'os_version_id' => $osVersionId, 'os_pretty_name' => $osPrettyName, 'kernel' => $kernel, 'arch' => $arch, 'docker_engine_id' => $dockerEngineId];
        foreach ($bounded as $field => $value) {
            if (null === $value) {
                continue;
            }

            if (u($value)->length() > self::MAX || [] !== u($value)->match('/[\p{Cc}\p{Cf}]/')) {
                throw new InvalidHostFacts($field);
            }
        }

        if (null !== $machineIdHash && [] === u($machineIdHash)->match('/^[0-9a-f]{64}$/')) {
            throw new InvalidHostFacts('machine_id_hash');
        }
    }

    /**
     * The facts an inventory observed, over these ones: a field it did not see (null or empty) keeps its value, the
     * machine id hash is never taken from it. Built once, so a missing hostname does not drop the other fields.
     *
     * @throws InvalidHostFacts
     */
    public function mergedWith(
        ?string $hostname = null,
        ?string $osId = null,
        ?string $osVersionId = null,
        ?string $osPrettyName = null,
        ?string $kernel = null,
        ?string $arch = null,
        ?string $dockerEngineId = null,
    ): self {
        return new self(
            self::seen($hostname) ?? $this->hostname,
            self::seen($osId) ?? $this->osId,
            self::seen($osVersionId) ?? $this->osVersionId,
            self::seen($osPrettyName) ?? $this->osPrettyName,
            self::seen($kernel) ?? $this->kernel,
            self::seen($arch) ?? $this->arch,
            self::seen($dockerEngineId) ?? $this->dockerEngineId,
            $this->machineIdHash,
        );
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

        $docker = null !== $this->dockerEngineId && '' !== $this->dockerEngineId ? HostIdentity::docker($this->dockerEngineId) : null;
        $machine = null !== $this->machineIdHash ? new HostIdentity('machine', $this->machineIdHash) : null;
        $preferred = AgentMode::Container === $mode ? [$docker, $machine] : [$machine, $docker];
        foreach ($preferred as $identity) {
            if (null !== $identity) {
                return $identity;
            }
        }

        return new HostIdentity(HostIdentity::HOSTNAME, u($this->hostname)->lower()->toString());
    }

    private static function seen(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }
}
