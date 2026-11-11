<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent\Handler;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Command\Agent\StoreSnapshot;
use Inventory\Application\Protocol\SnapshotDecoder;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * A snapshot of an open inventory of this agent, announced by its manifest, under its media type: checked (gzip,
 * size, digest, format) and stored once per team. Already stored: nothing to do.
 */
final readonly class StoreSnapshotHandler implements CommandHandler
{
    public function __construct(private InventoryAgents $agents, private SnapshotBlobRepository $blobs, private ClockInterface $clock)
    {
    }

    public function __invoke(StoreSnapshot $command): void
    {
        $agent = $this->agents->approved($command->agentId);
        $run = $this->agents->runOf($agent, $command->inventoryId);
        $run->assertOpen();
        $digest = Digest::of($command->digest);
        $ref = $run->manifest()->refOf($digest) ?? throw new InvalidInventory('This digest is not in the manifest.');
        if ($command->contentType !== $ref->mediaType->value) {
            throw new InvalidInventory(\sprintf('Content-Type must be %s.', $ref->mediaType->value));
        }

        if (null !== $this->blobs->find($run->teamId(), $digest)) {
            return;
        }

        $size = SnapshotDecoder::check($command->gzip, $digest, $ref->mediaType);
        $this->blobs->add(SnapshotBlob::store(Uuid::v7(), $run->teamId(), $digest, $ref->mediaType, $size, $command->gzip, $this->clock->now()));
    }
}
