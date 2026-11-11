<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * queued → delivered → executed | failed | rejected; delivered without a result 60 s after its expiry → expired;
 * queued for an agent that got revoked → cancelled.
 */
enum OrderStatus: string
{
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Executed = 'executed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return self::Queued === $this || self::Delivered === $this;
    }
}
