<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\ListRecipients;
use Notification\Application\Query\View\Pages;
use Notification\Application\Query\View\RecipientRow;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Query\Page;
use Symfony\Component\Uid\Uuid;

/**
 * The attached recipients first (their email from Identity), then the addresses still invited, the oldest first.
 */
final readonly class ListRecipientsHandler implements QueryHandler
{
    public function __construct(
        private NotificationAccess $access,
        private SubscriptionRepository $subscriptions,
        private RecipientInvitationRepository $invitations,
        private QueryBus $queries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Page<RecipientRow>
     */
    public function __invoke(ListRecipients $query): Page
    {
        $this->access->assertManages($query->teamId, $query->projectId);
        $project = Uuid::fromString($query->projectId);
        $attached = array_values(array_filter($this->subscriptions->ofScope(Uuid::fromString($query->teamId), $project), static fn (Subscription $s): bool => null !== $s->addedBy()));
        $accounts = [] === $attached ? [] : $this->queries->ask(new FindAccounts(array_map(static fn (Subscription $s): string => $s->userId()->toRfc4122(), $attached)));

        $rows = [
            ...array_map(static fn (Subscription $s): RecipientRow => new RecipientRow($s->id()->toRfc4122(), RecipientRow::ATTACHED, $accounts[$s->userId()->toRfc4122()]->email ?? '', $s->frequency()->value, $s->createdAt()), $attached),
            ...array_map(static fn (RecipientInvitation $i): RecipientRow => new RecipientRow($i->id()->toRfc4122(), RecipientRow::INVITED, $i->email(), $i->frequency()->value, $i->createdAt()), $this->invitations->pendingOfProject($project, $this->clock->now())),
        ];

        return Pages::of($rows, $query->page, static fn (RecipientRow $row): string => $row->email);
    }
}
