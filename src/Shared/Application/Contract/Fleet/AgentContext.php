<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

final readonly class AgentContext
{
    public function __construct(
        public string $agentId,
        public string $teamId,
        public string $hostId,
        public string $status,
        public string $mode,
        public ?string $scope,
        public ?string $composeProject,
        public ?string $defaultEnvironmentId,
    ) {
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->status;
    }

    /**
     * A `compose` / `labeled` agent sees its Compose project only (protocol §8.4): never the host.
     */
    public function isConfinedToComposeProject(): bool
    {
        return 'container' === $this->mode && \in_array($this->scope, ['compose', 'labeled'], true);
    }

    /**
     * An embedded agent sees its own container only (protocol §8.4): its file system, never Docker.
     */
    public function isEmbedded(): bool
    {
        return 'embedded' === $this->mode;
    }
}
