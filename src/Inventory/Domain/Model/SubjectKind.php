<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * The subjects of an inventory (protocol §8.2): three kinds of applications, the container image they run, and the
 * host itself (its OS packages).
 */
enum SubjectKind: string
{
    case Path = 'path';
    case ComposeService = 'compose_service';
    case Container = 'container';
    case ContainerImage = 'container_image';
    case Host = 'host';

    public function isApplication(): bool
    {
        return self::Path === $this || self::ComposeService === $this || self::Container === $this;
    }

    /**
     * The `subject_key` prefix of the kind (the host's key is `host` itself).
     */
    public function keyPrefix(): string
    {
        return match ($this) {
            self::Path => 'path:',
            self::ComposeService => 'compose:',
            self::Container => 'container:',
            self::ContainerImage => 'image:',
            self::Host => 'host',
        };
    }
}
