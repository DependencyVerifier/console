<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Agent\Handler;

use Inventory\Application\Agent\InventoryAgents;
use Inventory\Application\Command\Agent\SubmitManifest;
use Inventory\Application\Protocol\ManifestReader;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\ScopeViolation;
use Inventory\Domain\Repository\InventoryRunRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Protocol\AgentPayload;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * An approved agent announces an inventory. A compose / labeled agent that steps out of its Compose project gets the
 * whole inventory refused before anything is stored (protocol §8.4). The same id again from the same agent is a
 * retry; from another agent, it is refused.
 */
final readonly class SubmitManifestHandler implements CommandHandler
{
    public function __construct(private InventoryAgents $agents, private InventoryRunRepository $runs, private ClockInterface $clock)
    {
    }

    public function __invoke(SubmitManifest $command): void
    {
        $agent = $this->agents->approved($command->agentId);
        $manifest = ManifestReader::read(AgentPayload::parse($command->body));
        if ($agent->isConfinedToComposeProject()) {
            $outside = $manifest->outsideComposeProject((string) $agent->composeProject);
            if ([] !== $outside) {
                throw self::outside(\sprintf('the Compose project "%s"', $agent->composeProject), $outside);
            }
        }

        $outside = $agent->isEmbedded() ? $manifest->outsideOwnFileSystem() : [];
        if ([] !== $outside) {
            throw self::outside('the container of an embedded agent', $outside);
        }

        $id = Uuid::fromString($command->inventoryId);
        $existing = $this->runs->find($id);
        if (null !== $existing) {
            if (!$existing->isFrom(Uuid::fromString($agent->agentId))) {
                throw new InvalidInventory('This inventory_id belongs to another agent.');
            }

            return;
        }

        $this->runs->add(InventoryRun::open($id, Uuid::fromString($agent->agentId), Uuid::fromString($agent->teamId), Uuid::fromString($agent->hostId), $manifest, $this->clock->now()));
    }

    /**
     * @param list<string> $keys
     */
    private static function outside(string $scope, array $keys): ScopeViolation
    {
        return new ScopeViolation(\sprintf('Outside %s: %s.', $scope, u(', ')->join(\array_slice($keys, 0, 5))->truncate(500)));
    }
}
