<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * open (manifest received) → completed (every snapshot stored, queued for processing) → processed; or rejected
 * (scope violation), or discarded (not completed within an hour).
 */
enum RunStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case Discarded = 'discarded';
}
