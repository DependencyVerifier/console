<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

/**
 * What a finding is about: an application (in a project, through its environment) or a host (its OS, runtimes and
 * OS packages, at the team level).
 */
enum SubjectType: string
{
    case Application = 'application';
    case Host = 'host';
}
