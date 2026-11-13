<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

enum DeliveryChannel: string
{
    case Email = 'email';
    case Webhook = 'webhook';
}
