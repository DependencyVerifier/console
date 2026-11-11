<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Agent\Handler;

use Fleet\Application\Command\Agent\Handler\RecordAgentPollHandler;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Query\Agent\GetPollAnswer;
use Fleet\Application\Query\View\PollAnswer;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;

/**
 * next_poll_in: 60 s while pending (approval is awaited), 300 s once approved (no long polling yet).
 */
final readonly class GetPollAnswerHandler implements QueryHandler
{
    public const int PENDING_POLL = 60;
    public const int APPROVED_POLL = 300;

    public function __construct(private AgentRepository $agents, private AgentOrderRepository $orders, private OrderSigner $signer, private ClockInterface $clock)
    {
    }

    public function __invoke(GetPollAnswer $query): PollAnswer
    {
        $agent = $this->agents->find(AgentId::fromString($query->agentId)) ?? throw new ProtocolRefusal(Problem::UnknownAgent);
        if (!$agent->isApproved()) {
            return new PollAnswer($agent->status()->value, [], self::PENDING_POLL);
        }

        $now = $this->clock->now();
        $orders = [];
        foreach ($this->orders->openOf($agent->id(), RecordAgentPollHandler::MAX_ORDERS) as $order) {
            if ($order->isDeliverableAt($now)) {
                $orders[] = $this->signer->envelope($order);
            }
        }

        return new PollAnswer($agent->status()->value, $orders, self::APPROVED_POLL);
    }
}
