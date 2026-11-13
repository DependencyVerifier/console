<?php

declare(strict_types=1);

namespace Notification\Application\Command\Send\Handler;

use Notification\Application\Command\Send\SendDelivery;
use Notification\Application\Notice\Recipients;
use Notification\Application\Port\NoticeView;
use Notification\Application\Port\NotificationMailer;
use Notification\Application\Port\WebhookCaller;
use Notification\Application\Webhook\WebhookRequest;
use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryChannel;
use Notification\Domain\Model\Notice;
use Notification\Domain\Model\WebhookSecretBinding;
use Notification\Domain\Repository\DeliveryRepository;
use Notification\Domain\Repository\NoticeRepository;
use Notification\Domain\Repository\NotificationRuleRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\SecretBox;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt of an immediate delivery. A delivery not due (sent, dropped, failed, or waiting for its next attempt) is
 * left as is: a message handled twice sends once. The recipient is judged again: who can no longer read it, or a
 * webhook no longer called, gets nothing. The outcome is written in this transaction, never thrown away by a rollback.
 */
final readonly class SendDeliveryHandler implements CommandHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private NoticeRepository $notices,
        private NotificationRuleRepository $rules,
        private WebhookChannelRepository $webhooks,
        private Recipients $recipients,
        private NotificationMailer $mailer,
        private WebhookCaller $caller,
        private SecretBox $secrets,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendDelivery $command): void
    {
        $now = $this->clock->now();
        $delivery = Uuid::isValid($command->deliveryId) ? $this->deliveries->find(Uuid::fromString($command->deliveryId)) : null;
        if (null === $delivery || !$delivery->isDue($now)) {
            return;
        }

        $notice = $this->notices->find($delivery->noticeId());
        $outcome = null === $notice ? false : match ($delivery->channel()) {
            DeliveryChannel::Email => $this->email($delivery, $notice),
            DeliveryChannel::Webhook => $this->webhook($delivery, $notice, $now),
        };

        match (true) {
            false === $outcome => $delivery->drop($now),
            null === $outcome => $delivery->sent($now),
            default => $delivery->attemptFailed($outcome, $now),
        };
    }

    /**
     * @return string|false|null the failure, false when nobody may read it, null once sent
     */
    private function email(Delivery $delivery, Notice $notice): string|false|null
    {
        $user = $delivery->target();
        $subscription = $this->recipients->subscription($user, $notice->teamId(), $notice->projectId());
        $email = null === $subscription ? null : $this->recipients->email($user);
        if (null === $subscription || null === $email) {
            return false;
        }

        $team = $notice->teamId()->toRfc4122();

        return $this->mailer->notice($email, new NoticeView(
            $notice->id()->toRfc4122(),
            $notice->event()->value,
            $team,
            $this->recipients->teamNames([$team])[$team] ?? '',
            $notice->projectId()?->toRfc4122(),
            $notice->content(),
            $notice->createdAt(),
            $subscription->id()->toRfc4122(),
        ));
    }

    /**
     * @return string|false|null the failure, false when the webhook is gone or no longer called, null once sent
     */
    private function webhook(Delivery $delivery, Notice $notice, \DateTimeImmutable $now): string|false|null
    {
        $webhook = Uuid::isValid($delivery->target()) ? $this->webhooks->find(Uuid::fromString($delivery->target())) : null;
        $rule = $this->rules->find($notice->ruleId());
        if (null === $webhook || null === $rule || !\in_array($delivery->target(), $rule->webhookIds(), true) || !$webhook->teamId()->equals($notice->teamId())) {
            return false;
        }

        $secret = $this->secrets->decrypt($webhook->secret(), WebhookSecretBinding::for($webhook->id()));
        $error = $this->caller->call(WebhookRequest::for($webhook->url(), $secret, $notice, $delivery, $now));
        $webhook->called($now, $error);

        return $error;
    }
}
