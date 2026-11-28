<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

use function Symfony\Component\String\u;

/**
 * What the running build calls itself (`APP_VERSION`): shown as is in the footer, and a version only when it is a
 * strict `X.Y.Z`. The length bound is defensive, for the display only: the source (the image build) is trusted.
 */
final readonly class BuildLabel
{
    public const int MAX_LENGTH = 64;

    private function __construct(private string $label, private ?ConsoleVersion $version)
    {
    }

    public static function fromRaw(string $raw): self
    {
        $label = u($raw)->trim()->truncate(self::MAX_LENGTH)->toString();

        return new self($label, ConsoleVersion::tryFrom($label));
    }

    public function label(): string
    {
        return $this->label;
    }

    public function version(): ?ConsoleVersion
    {
        return $this->version;
    }
}
