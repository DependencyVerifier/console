<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * What a history entry is about: the facts or the OS packages of a host (its id), an application, an image.
 */
enum SnapshotSubject: string
{
    case HostFacts = 'host_facts';
    case HostPackages = 'host_packages';
    case Application = 'application';
    case Image = 'image';
}
