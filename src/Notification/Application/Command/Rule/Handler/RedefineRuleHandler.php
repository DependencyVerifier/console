<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule\Handler;

use Notification\Application\Command\Rule\RedefineRule;
use Shared\Application\Bus\CommandHandler;

final readonly class RedefineRuleHandler implements CommandHandler
{
    public function __construct(private RuleOfScope $rules, private RuleInput $input)
    {
    }

    public function __invoke(RedefineRule $command): void
    {
        $rule = $this->rules->get($command->ruleId);
        $settings = $command->settings;
        $rule->redefine(
            $this->input->environment($rule->teamId()->toRfc4122(), $rule->projectId()?->toRfc4122(), $settings),
            $settings->name,
            $this->input->events($settings),
            $this->input->thresholds($settings),
            $this->input->webhooks($rule->teamId(), $settings),
            $settings->emailSubscribers,
        );
    }
}
