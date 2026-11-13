<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

/**
 * When a recipient hears of an event: at once, in the daily digest, in the weekly one (Monday), or never.
 */
enum Frequency: string
{
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Off = 'off';
}
