<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use function Symfony\Component\String\u;

/**
 * A link a human gives an application (runbook, wiki, repository, dashboard…): an absolute http(s) URL without
 * credentials, and an optional label. Shown as a link that opens elsewhere, never fetched by the console.
 */
final readonly class DocumentationLink
{
    public const int MAX_URL = 2048;
    public const int MAX_LABEL = 100;

    public string $url;
    public ?string $label;

    public function __construct(string $url, ?string $label = null)
    {
        $url = u($url)->trim();
        // A scheme, a host (no credentials: no "@" before the path), then anything printable without spaces.
        if ($url->length() > self::MAX_URL || [] === $url->match('#^https?://[^/?\#@\s\p{C}]+([/?\#][^\s\p{C}]*)?$#iu')) {
            throw new InvalidDocumentationLink('An http(s) URL, without credentials, of 2048 characters at most.');
        }

        $label = u($label ?? '')->trim();
        if ($label->length() > self::MAX_LABEL || [] !== $label->match('/\p{C}/u')) {
            throw new InvalidDocumentationLink('A label of 100 printable characters at most.');
        }

        $this->url = $url->toString();
        $this->label = $label->isEmpty() ? null : $label->toString();
    }

    /**
     * @return array{url: string, label: ?string}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'label' => $this->label];
    }
}
