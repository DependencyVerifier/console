<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Order\Handler;

use Fleet\Application\Command\Order\RotateConsoleKey;
use Fleet\Application\Port\ConsoleSigner;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\ConsoleKeyRotationNotPrepared;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Queues ROTATE_CONSOLE_KEY for each approved agent that does not pin the next key yet and has none on its way. Run
 * it again until every agent is on the next key, then make the next key the current one.
 */
final readonly class RotateConsoleKeyHandler implements CommandHandler
{
    public function __construct(private ConsoleSigner $signer, private AgentRepository $agents, private AgentOrderRepository $orders, private ClockInterface $clock)
    {
    }

    public function __invoke(RotateConsoleKey $command): void
    {
        $next = $this->signer->nextKey();
        if (null === $next || $next->fingerprint() === $this->signer->publicKey()->fingerprint()) {
            throw new ConsoleKeyRotationNotPrepared();
        }

        foreach ($this->agents->approved() as $agent) {
            if ($agent->pinnedConsoleKey() === $next->fingerprint() || true === $this->orders->lastOf($agent->id(), OrderType::RotateConsoleKey)?->status()->isOpen()) {
                continue;
            }

            $this->orders->add(AgentOrder::rotateConsoleKey(AgentOrderId::of(Uuid::v7()), $agent, $next, $this->clock->now()));
        }
    }
}
