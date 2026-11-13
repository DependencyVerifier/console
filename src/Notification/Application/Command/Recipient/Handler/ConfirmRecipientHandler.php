<?php

declare(strict_types=1);

namespace Notification\Application\Command\Recipient\Handler;

use Notification\Application\Command\Recipient\ConfirmRecipient;
use Notification\Domain\Model\RecipientLinkInvalid;
use Notification\Domain\Repository\SubscriptionRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenHasher;

final readonly class ConfirmRecipientHandler implements CommandHandler
{
    public function __construct(private SubscriptionRepository $subscriptions, private TokenHasher $hasher, private ClockInterface $clock)
    {
    }

    public function __invoke(ConfirmRecipient $command): void
    {
        $subscription = $this->subscriptions->findByConfirmationHash($this->hasher->hash($command->token)) ?? throw new RecipientLinkInvalid();
        $subscription->confirm($this->clock->now());
    }
}
