<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use function Symfony\Component\String\u;

/**
 * How an agent runs (protocol §5.1): mode, container scope and Compose project, binary version.
 */
final readonly class AgentRuntime
{
    public string $version;

    public function __construct(
        public AgentMode $mode,
        public ?AgentScope $scope,
        public ?string $composeProject,
        string $version,
    ) {
        $this->version = self::version($version);
        $needsScope = AgentMode::Container === $mode;
        if ($needsScope !== (null !== $scope)) {
            throw new InvalidEnrollmentPayload();
        }

        $needsProject = null !== $scope && $scope->needsComposeProject();
        if ($needsProject !== (null !== $composeProject) || (null !== $composeProject && !self::bounded($composeProject))) {
            throw new InvalidEnrollmentPayload();
        }
    }

    public static function version(string $version): string
    {
        if ([] === u($version)->match('/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:[-+][0-9A-Za-z.+-]{1,40})?$/')) {
            throw new InvalidEnrollmentPayload();
        }

        return $version;
    }

    private static function bounded(string $value): bool
    {
        $length = u($value)->length();

        return $length > 0 && $length <= 255;
    }
}
