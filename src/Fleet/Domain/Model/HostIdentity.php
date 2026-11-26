<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * What makes two agents run on the same host of a team: unique per team (partial index on non-deleted hosts).
 */
#[ORM\Embeddable]
final readonly class HostIdentity
{
    public const string HOSTNAME = 'hostname';
    public const string DOCKER = 'docker';

    public function __construct(
        #[ORM\Column(name: 'identity_kind', length: 16)]
        public string $kind,
        #[ORM\Column(name: 'identity_value', length: 255)]
        public string $value,
    ) {
    }

    /**
     * The identity of the Docker engine an agent runs on: the same value at enrollment (HostFacts::identityFor) and
     * when a host is promoted (Host::promoteToDockerEngine), so a later enrollment finds the promoted host.
     */
    public static function docker(string $engineId): self
    {
        return new self(self::DOCKER, $engineId);
    }

    /**
     * Only the last resort of HostFacts::identityFor: no Docker engine id nor machine id was known.
     */
    public function isHostname(): bool
    {
        return self::HOSTNAME === $this->kind;
    }
}
