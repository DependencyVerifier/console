<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify\Handler;

use Notification\Application\Command\Notify\NotifyAgentAwaitingApproval;
use Notification\Application\Notice\NoticeWriter;
use Notification\Application\Notice\Occurrence;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationSubject;
use Shared\Application\Bus\CommandHandler;

final readonly class NotifyAgentAwaitingApprovalHandler implements CommandHandler
{
    public function __construct(private NoticeWriter $writer)
    {
    }

    public function __invoke(NotifyAgentAwaitingApproval $command): void
    {
        $this->writer->write(new Occurrence(
            $command->teamId,
            new NotificationSubject(NotificationEvent::AgentPending),
            'agent-pending:'.$command->agentId,
            ['agent' => ['id' => $command->agentId, 'hostname' => $command->hostname]],
        ));
    }
}
