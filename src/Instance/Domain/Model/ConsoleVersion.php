<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

use Composer\Semver\Comparator;

use function Symfony\Component\String\u;

/**
 * A released version of the console: always a strict `X.Y.Z`, kept without its `v`. Anything else (`dev`, a
 * `git describe` label, a pre-release) is not a version the console compares, so it has no instance.
 */
final readonly class ConsoleVersion
{
    private function __construct(private string $value)
    {
    }

    public static function tryFrom(string $raw): ?self
    {
        $matched = u($raw)->match('/^v?(\d+\.\d+\.\d+)$/D');

        return isset($matched[1]) && \is_string($matched[1]) ? new self($matched[1]) : null;
    }

    public function isNewerThan(self $other): bool
    {
        return Comparator::greaterThan($this->value, $other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
