<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * The two snapshot formats of protocol §8.1.
 */
enum MediaType: string
{
    case HostFacts = 'application/vnd.depverify.host-facts+json;version=1';
    case CycloneDx = 'application/vnd.cyclonedx+json;version=1.6';
}
