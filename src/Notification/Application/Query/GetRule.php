<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Notification\Application\Query\View\RuleView;
use Shared\Application\Bus\Query;

/**
 * A rule for its edit form (who manages it).
 *
 * @implements Query<RuleView>
 */
final readonly class GetRule implements Query
{
    public function __construct(public string $ruleId)
    {
    }
}
