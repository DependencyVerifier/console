<?php

declare(strict_types=1);

namespace Notification\Application\Query\Handler;

use Notification\Application\Query\ListRules;
use Notification\Application\Query\View\Pages;
use Notification\Application\Query\View\RuleRow;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Query\Page;
use Symfony\Component\Uid\Uuid;

/**
 * Its webhooks named (a deleted one is already forgotten), its environment named.
 */
final readonly class ListRulesHandler implements QueryHandler
{
    public function __construct(private NotificationAccess $access, private NotificationRuleRepository $rules, private WebhookChannelRepository $webhooks, private QueryBus $queries)
    {
    }

    /**
     * @return Page<RuleRow>
     */
    public function __invoke(ListRules $query): Page
    {
        $this->access->assertSees($query->teamId, $query->projectId);
        $team = Uuid::fromString($query->teamId);
        $rules = $this->rules->ofScope($team, null === $query->projectId ? null : Uuid::fromString($query->projectId));
        $webhooks = [];
        foreach ($this->webhooks->ofTeam($team) as $webhook) {
            $webhooks[$webhook->id()->toRfc4122()] = $webhook->name();
        }

        $environments = [];
        if (array_any($rules, static fn (NotificationRule $r): bool => null !== $r->environmentId())) {
            foreach ($this->queries->ask(new ListTeamEnvironments($query->teamId)) as $environment) {
                $environments[$environment->environmentId] = $environment->name;
            }
        }

        $rows = array_map(static function (NotificationRule $rule) use ($webhooks, $environments): RuleRow {
            $thresholds = $rule->thresholds();
            $environment = $rule->environmentId()?->toRfc4122();

            return new RuleRow(
                $rule->id()->toRfc4122(),
                $rule->name(),
                null === $environment ? null : $environments[$environment] ?? '',
                array_map(static fn (NotificationEvent $e): string => $e->value, $rule->events()),
                $thresholds->minSeverity,
                $thresholds->minCvss,
                $thresholds->knownExploitedOnly,
                $thresholds->minEpss,
                $thresholds->silentHours,
                array_values(array_intersect_key($webhooks, array_flip($rule->webhookIds()))),
                $rule->emailsSubscribers(),
            );
        }, $rules);

        return Pages::of($rows, $query->page, static fn (RuleRow $row): string => $row->name);
    }
}
