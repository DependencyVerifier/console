<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * Pending (until confirmed, or its link expires), confirmed (the address subscribed), declined (the address belongs to
 * an account that signs in without seeing the project: it is to be invited), cancelled by a manager.
 */
enum RecipientInvitationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
}
