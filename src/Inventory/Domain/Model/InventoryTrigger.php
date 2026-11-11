<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

enum InventoryTrigger: string
{
    case Scheduled = 'scheduled';
    case ScanNow = 'scan_now';
    case ImageChange = 'image_change';
    case Startup = 'startup';
}
