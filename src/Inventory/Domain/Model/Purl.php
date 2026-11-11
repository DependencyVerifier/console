<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use function Symfony\Component\String\u;

/**
 * A package URL (`pkg:type/namespace/name@version?qualifiers#subpath`), read for what the matching needs: the type
 * (ecosystem: composer, npm, deb, rpm, apk…), the namespace, the name and the version, percent-decoded.
 */
final readonly class Purl
{
    public const int MAX = 2048;

    private function __construct(public string $value, public string $type, public ?string $namespace, public string $name, public ?string $version)
    {
    }

    public static function parse(string $purl): self
    {
        $text = u($purl);
        if ($text->length() > self::MAX || !$text->startsWith('pkg:')) {
            throw new InvalidInventory('Not a package URL.');
        }

        $rest = $text->after('pkg:')->trimStart('/')->before('#')->before('?');
        $version = null;
        if ($rest->containsAny('@')) {
            $version = rawurldecode($rest->afterLast('@')->toString());
            $rest = $rest->beforeLast('@');
        }

        $segments = array_values(array_filter(array_map(static fn ($s): string => $s->toString(), $rest->split('/')), static fn (string $s): bool => '' !== $s));
        if (\count($segments) < 2 || [] === u($segments[0])->match('/^[a-zA-Z][a-zA-Z0-9.+-]*$/')) {
            throw new InvalidInventory('Not a package URL.');
        }

        $type = u($segments[0])->lower()->toString();
        $name = rawurldecode($segments[\count($segments) - 1]);
        $namespace = \count($segments) > 2 ? u('/')->join(array_map(rawurldecode(...), \array_slice($segments, 1, -1)))->toString() : null;

        return new self($purl, $type, $namespace, $name, '' === $version ? null : $version);
    }
}
