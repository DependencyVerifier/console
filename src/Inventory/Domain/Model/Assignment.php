<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * How an application got (or lost) its environment: never by the automatic rules once a human decided.
 */
enum Assignment: string
{
    case None = 'none';
    case Auto = 'auto';
    case Manual = 'manual';
}
