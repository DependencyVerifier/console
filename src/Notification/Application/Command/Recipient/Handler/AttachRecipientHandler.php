<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\AttachRecipient;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\RecipientQuotaReached;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Locking\TransactionLock;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

/**
 * Permission first, then under the recipients lock of the team: the quota (the addresses invited and the accounts
 * attached, each once), then the invitation and its link, or a new link for one still pending. Never a word about the
 * accounts of the instance.
 */
final readonly class AttachRecipientHandler implements CommandHandler
{
    public function __construct(
        private NotificationAccess $access,
        private TransactionLock $lock,
        private RecipientInvitationRepository $invitations,
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

        $email = RecipientInvitation::normalize($command->email);
        $this->lock->acquire('notification.recipients:'.$command->teamId);
        $team = Uuid::fromString($command->teamId);
        $project = Uuid::fromString($command->projectId);
        $now = $this->clock->now();
        $token = $this->tokens->urlToken();

        $pending = $this->invitations->pending($project, $email);
        if (null !== $pending) {
            $pending->resend($token, $this->hasher->hash($token), $now);

            return;
        }

        $invited = $this->invitations->pendingEmailsOfTeam($team, $now);
        $limit = $this->queries->ask(new GetTeamQuota($command->teamId, 'max_notification_only_accounts'));
        if (null !== $limit && !\in_array($email, $invited, true) && \count($invited) + \count($this->subscriptions->attachedRecipients($team)) >= $limit) {
            throw new RecipientQuotaReached($limit);
        }

        $this->invitations->add(RecipientInvitation::issue(Uuid::v7(), $team, $project, $email, $frequency, $this->actor->actor()->userId(), $token, $this->hasher->hash($token), $now));
    }
}
