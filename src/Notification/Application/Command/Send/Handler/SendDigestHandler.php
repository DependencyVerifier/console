<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send\Handler;

use Notification\Application\Command\Send\SendDigest;
use Notification\Application\Notice\Recipients;
use Notification\Application\Port\NoticeView;
use Notification\Application\Port\NotificationMailer;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\DeliveryStatus;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Repository\DeliveryRepository;
use Notification\Domain\Repository\NoticeRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * One email gathering the pending deliveries of an account: what it may no longer read is dropped, the rest leaves
 * at once, by team then scope. A failed digest is attempted again with the next one.
 */
final readonly class SendDigestHandler implements CommandHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private NoticeRepository $notices,
        private Recipients $recipients,
        private NotificationMailer $mailer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendDigest $command): void
    {
        $frequency = Frequency::from($command->frequency);
        $now = $this->clock->now();
        $pending = [];
        foreach ($command->deliveryIds as $id) {
            $delivery = Uuid::isValid($id) ? $this->deliveries->find(Uuid::fromString($id)) : null;
            if (null !== $delivery && DeliveryStatus::Pending === $delivery->status() && DeliveryChannel::Email === $delivery->channel() && $frequency === $delivery->frequency() && $command->userId === $delivery->target()) {
                $pending[] = $delivery;
            }
        }

        $notices = $this->notices->findMany(array_map(static fn (Delivery $d): Uuid => $d->noticeId(), $pending));
        $email = [] === $pending ? null : $this->recipients->email($command->userId);
        $sent = [];
        $views = [];
        $subscriptions = [];
        foreach ($pending as $delivery) {
            $notice = $notices[$delivery->noticeId()->toRfc4122()] ?? null;
            $scope = null === $notice ? '' : $notice->teamId()->toRfc4122().' '.($notice->projectId()?->toRfc4122() ?? '');
            if (null !== $notice && null !== $email && !\array_key_exists($scope, $subscriptions)) {
                $subscriptions[$scope] = $this->recipients->subscription($command->userId, $notice->teamId(), $notice->projectId());
            }

            $subscription = $subscriptions[$scope] ?? null;
            if (null === $notice || null === $subscription) {
                $delivery->drop($now);

                continue;
            }

            $sent[] = $delivery;
            $views[] = new NoticeView($notice->id()->toRfc4122(), $notice->event()->value, $notice->teamId()->toRfc4122(), '', $notice->projectId()?->toRfc4122(), $notice->content(), $notice->createdAt(), $subscription->id()->toRfc4122());
        }

        if (null === $email || [] === $views) {
            return;
        }

        $error = $this->mailer->digest($email, $frequency, $this->sorted($views));
        foreach ($sent as $delivery) {
            null === $error ? $delivery->sent($now) : $delivery->attemptFailed($error, $now);
        }
    }

    /**
     * Named, by team name, then the fleet before the projects, then the oldest first.
     *
     * @param non-empty-list<NoticeView> $views
     *
     * @return non-empty-list<NoticeView>
     */
    private function sorted(array $views): array
    {
        $names = $this->recipients->teamNames(array_values(array_unique(array_map(static fn (NoticeView $v): string => $v->teamId, $views))));
        $named = array_map(static fn (NoticeView $v): NoticeView => new NoticeView($v->noticeId, $v->event, $v->teamId, $names[$v->teamId] ?? '', $v->projectId, $v->content, $v->occurredAt, $v->subscriptionId), $views);
        usort($named, static fn (NoticeView $a, NoticeView $b): int => [$a->teamName, $a->teamId, $a->projectId ?? '', $a->occurredAt] <=> [$b->teamName, $b->teamId, $b->projectId ?? '', $b->occurredAt]);

        return $named;
    }
}
