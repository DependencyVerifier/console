<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\AttachRecipient;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\RecipientQuotaReached;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\RecipientProvisioning;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Contract\Organization\RecipientAdmission;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Locking\TransactionLock;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

/**
 * Permission first, then under the recipients lock of the team: the account (Identity), the quota, the membership
 * (Organization), the subscription and its confirmation link. Attached again while unconfirmed: a new link; already
 * confirmed: nothing changes.
 */
final readonly class AttachRecipientHandler implements CommandHandler
{
    public function __construct(
        private NotificationAccess $access,
        private TransactionLock $lock,
        private RecipientProvisioning $accounts,
        private RecipientAdmission $admission,
        private SubscriptionRepository $subscriptions,
        private QueryBus $queries,
        private CurrentActor $actor,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AttachRecipient $command): void
    {
        $this->access->assertManages($command->teamId, $command->projectId);
        $frequency = Frequency::tryFrom($command->frequency);
        if (null === $frequency || Frequency::Off === $frequency) {
            throw new InvalidNotificationSetting('A frequency: immediate, daily or weekly.');
        }

        $this->lock->acquire('notification.recipients:'.$command->teamId);

        $account = $this->accounts->notificationAccount($command->email, Uuid::v7()->toRfc4122());
        $team = Uuid::fromString($command->teamId);
        $project = Uuid::fromString($command->projectId);
        $user = Uuid::fromString($account->userId);
        $now = $this->clock->now();
        $token = $this->tokens->urlToken();

        $existing = $this->subscriptions->of($user, $team, $project);
        if (null !== $existing) {
            $existing->sendConfirmation($token, $this->hasher->hash($token), $now);

            return;
        }

        $attached = $this->subscriptions->attachedRecipients($team);
        $limit = $this->queries->ask(new GetTeamQuota($command->teamId, 'max_notification_only_accounts'));
        if (null !== $limit && !\in_array($account->userId, $attached, true) && \count($attached) >= $limit) {
            throw new RecipientQuotaReached($limit);
        }

        $this->admission->admitRecipient($command->teamId, $command->projectId, $account->userId);
        $this->subscriptions->add(Subscription::attach(Uuid::v7(), $team, $project, $user, $frequency, $this->actor->actor()->userId(), $token, $this->hasher->hash($token), $now));
    }
}
