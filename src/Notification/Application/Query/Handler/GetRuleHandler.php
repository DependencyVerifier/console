<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Command\Rule\Handler\RuleOfScope;
use Notification\Application\Command\Rule\RuleSettings;
use Notification\Application\Query\GetRule;
use Notification\Application\Query\View\RuleView;
use Notification\Domain\Model\NotificationEvent;
use Shared\Application\Bus\QueryHandler;

final readonly class GetRuleHandler implements QueryHandler
{
    public function __construct(private RuleOfScope $rules)
    {
    }

    public function __invoke(GetRule $query): RuleView
    {
        $rule = $this->rules->get($query->ruleId);
        $thresholds = $rule->thresholds();

        return new RuleView($rule->id()->toRfc4122(), $rule->teamId()->toRfc4122(), $rule->projectId()?->toRfc4122(), new RuleSettings(
            $rule->name(),
            array_map(static fn (NotificationEvent $e): string => $e->value, $rule->events()),
            $rule->environmentId()?->toRfc4122(),
            $thresholds->minSeverity,
            $thresholds->minCvss,
            $thresholds->knownExploitedOnly,
            $thresholds->minEpss,
            $thresholds->silentHours,
            $rule->webhookIds(),
            $rule->emailsSubscribers(),
        ));
    }
}
