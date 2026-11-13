<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * Pending (due at once or in a digest), sent, failed after its last attempt, or dropped (its recipient no longer sees
 * it, unsubscribed, or was removed).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Dropped = 'dropped';
}
