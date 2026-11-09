<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messenger;

use Shared\Domain\BusinessRuleViolated;

final class FixtureRuleViolated extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('fixture_rule');
    }
}
