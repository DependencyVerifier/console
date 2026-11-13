<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * The closed order catalogue of protocol v1 (§7.3): never an arbitrary command.
 */
enum OrderType: string
{
    case ScanNow = 'SCAN_NOW';
    case SetConfig = 'SET_CONFIG';
    case UpgradeAgent = 'UPGRADE_AGENT';
    case RotateKey = 'ROTATE_KEY';
    case RotateConsoleKey = 'ROTATE_CONSOLE_KEY';
}
