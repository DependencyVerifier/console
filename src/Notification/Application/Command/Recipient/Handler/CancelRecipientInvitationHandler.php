<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\CancelRecipientInvitation;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

final readonly class CancelRecipientInvitationHandler implements CommandHandler
{
    public function __construct(private RecipientInvitationRepository $invitations, private NotificationAccess $access, private ClockInterface $clock)
    {
    }

    public function __invoke(CancelRecipientInvitation $command): void
    {
        $invitation = (Uuid::isValid($command->invitationId) ? $this->invitations->find(Uuid::fromString($command->invitationId)) : null) ?? throw ResourceNotFound::of('recipient');
        $this->access->assertManages($invitation->teamId()->toRfc4122(), $invitation->projectId()->toRfc4122());
        $invitation->cancel($this->clock->now());
    }
}
