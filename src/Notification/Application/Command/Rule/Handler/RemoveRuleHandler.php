<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule\Handler;

use Notification\Application\Command\Rule\RemoveRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Shared\Application\Bus\CommandHandler;

final readonly class RemoveRuleHandler implements CommandHandler
{
    public function __construct(private RuleOfScope $rule, private NotificationRuleRepository $rules)
    {
    }

    public function __invoke(RemoveRule $command): void
    {
        $this->rules->remove($this->rule->get($command->ruleId));
    }
}
