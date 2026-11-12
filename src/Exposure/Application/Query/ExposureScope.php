<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

/**
 * The perimeter of a dashboard or of a list of findings.
 */
enum ExposureScope: string
{
    case Team = 'team';
    case Project = 'project';
    case Environment = 'environment';
    case Host = 'host';
}
