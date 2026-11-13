<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\ConfirmRecipient;
use Notification\Domain\Model\RecipientLinkInvalid;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\RecipientProvisioning;
use Shared\Application\Contract\Organization\FilterRecipients;
use Shared\Application\Contract\Organization\RecipientAdmission;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Locking\TransactionLock;
use Symfony\Component\Uid\Uuid;

/**
 * The holder of the address followed its link: now only its account exists (Identity), a notification-only one
 * attached to the project on behalf of the manager (Organization, who checks the manager still may). An account that
 * signs in is never attached that way: it subscribes if it already sees the project, else the invitation is declined
 * (it is to be invited to the project, then subscribes itself).
 */
final readonly class ConfirmRecipientHandler implements CommandHandler
{
    public function __construct(
        private RecipientInvitationRepository $invitations,
        private SubscriptionRepository $subscriptions,
        private RecipientProvisioning $accounts,
        private RecipientAdmission $admission,
        private QueryBus $queries,
        private TransactionLock $lock,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfirmRecipient $command): void
    {
        $now = $this->clock->now();
        $invitation = $this->invitations->findByTokenHash($this->hasher->hash($command->token)) ?? throw new RecipientLinkInvalid();
        $invitation->assertUsable($now);
        $team = $invitation->teamId()->toRfc4122();
        $project = $invitation->projectId()->toRfc4122();
        $this->lock->acquire('notification.recipients:'.$team);

        $account = $this->accounts->recipientAccount($invitation->email(), Uuid::v7()->toRfc4122());
        if (!$account->signsIn) {
            $this->admission->admitRecipient($team, $project, $account->userId, $invitation->invitedBy()->toRfc4122());
        } elseif ([] === $this->queries->ask(new FilterRecipients($team, $project, [$account->userId]))) {
            $invitation->decline($now);

            return;
        }

        $user = Uuid::fromString($account->userId);
        if (null === $this->subscriptions->of($user, $invitation->teamId(), $invitation->projectId())) {
            $this->subscriptions->add(Subscription::attach(Uuid::v7(), $invitation->teamId(), $invitation->projectId(), $user, $invitation->frequency(), $invitation->invitedBy(), $now));
        }

        $invitation->confirm($now);
    }
}
