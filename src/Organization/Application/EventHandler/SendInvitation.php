<?php

declare(strict_types=1);

namespace Organization\Application\EventHandler;

use Organization\Application\Port\InvitationSender;
use Organization\Domain\Event\InvitationIssued;
use Organization\Domain\Event\InvitationReissued;
use Shared\Application\Bus\EventHandler;

/**
 * After the commit: the link leaves only for an invitation really stored.
 */
final readonly class SendInvitation implements EventHandler
{
    public function __construct(private InvitationSender $sender)
    {
    }

    public function __invoke(InvitationIssued|InvitationReissued $event): void
    {
        $this->sender->send($event->invitationId, $event->email, $event->plainToken);
    }
}
