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
    public function __construct(
        #[ORM\Column(name: 'identity_kind', length: 16)]
        public string $kind,
        #[ORM\Column(name: 'identity_value', length: 255)]
        public string $value,
    ) {
    }
}
