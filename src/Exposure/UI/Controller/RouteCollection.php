<?php

declare(strict_types=1);

namespace Exposure\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case FINDING = 'finding';
    case ADVISORY = 'advisory';
    case FINDING_ACCEPT = 'finding_accept';
    case FINDING_REVOKE = 'finding_revoke';
    case TEAM_FINDINGS = 'team_findings';
    case PROJECT_FINDINGS = 'project_findings';
    case ENVIRONMENT = 'environment';
    case TEAM_SUMMARY_FRAME = 'team_summary_frame';
    case PROJECT_SUMMARY_FRAME = 'project_summary_frame';
    case HOST_SUMMARY_FRAME = 'host_summary_frame';
    case PORTFOLIO_FRAME = 'portfolio_frame';

    public function prefixed(): string
    {
        return 'exposure_'.$this->value;
    }
}
