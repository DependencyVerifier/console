<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

use Notification\Application\Command\Rule\RuleSettings;

/**
 * A rule as its edit form shows it.
 */
final readonly class RuleView
{
    public function __construct(public string $id, public string $teamId, public ?string $projectId, public RuleSettings $settings)
    {
    }
}
