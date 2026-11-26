<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Inventory: the facts of a host an inventory observed, sent by the agent of its run, for the
 * context that owns hosts (Fleet). A field not observed is null. Untrusted data: bounds are the receiver's to judge.
 * A boundary DTO: more fields than an object of the model would carry.
 */
final readonly class HostFactsObserved implements DomainEvent
{
    public function __construct(
        public string $hostId,
        public string $teamId,
        public string $agentId,
        public ?string $hostname,
        public ?string $osId,
        public ?string $osVersionId,
        public ?string $osPrettyName,
        public ?string $kernel,
        public ?string $arch,
        public ?string $dockerEngineId,
    ) {
    }
}
