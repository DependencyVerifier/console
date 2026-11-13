<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Additional authenticated data of an encrypted webhook secret: the field and its channel, so a ciphertext copied to
 * another webhook does not decrypt.
 */
final class WebhookSecretBinding
{
    public static function for(Uuid $webhookId): string
    {
        return 'notification.webhook_secret:'.$webhookId->toRfc4122();
    }
}
