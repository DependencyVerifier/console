<?php

declare(strict_types=1);

namespace Notification\Application\Command\Rule\Handler;

use Notification\Application\Command\Rule\RuleSettings;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Repository\WebhookChannelRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Symfony\Component\Uid\Uuid;

/**
 * The values of a rule form checked against the team: its events known, its environment one of the project (404
 * otherwise), its webhooks the team's.
 */
final readonly class RuleInput
{
    public function __construct(private QueryBus $queries, private WebhookChannelRepository $webhooks)
    {
    }

    /**
     * @return list<NotificationEvent>
     */
    public function events(RuleSettings $settings): array
    {
        return array_map(static fn (string $event): NotificationEvent => NotificationEvent::tryFrom($event) ?? throw new InvalidNotificationSetting('An event of the list.'), $settings->events);
    }

    public function thresholds(RuleSettings $settings): RuleThresholds
    {
        return new RuleThresholds($settings->minSeverity, $settings->minCvss, $settings->knownExploitedOnly, $settings->minEpss, $settings->silentHours);
    }

    /**
     * @throws ResourceNotFound
     */
    public function environment(string $teamId, ?string $projectId, RuleSettings $settings): ?Uuid
    {
        if (null === $settings->environmentId) {
            return null;
        }

        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            if ($environment->environmentId === $settings->environmentId && $environment->projectId === $projectId) {
                return Uuid::fromString($environment->environmentId);
            }
        }

        throw ResourceNotFound::of('environment');
    }

    /**
     * @return list<Uuid>
     */
    public function webhooks(Uuid $teamId, RuleSettings $settings): array
    {
        $team = [];
        foreach ($this->webhooks->ofTeam($teamId) as $webhook) {
            $team[$webhook->id()->toRfc4122()] = $webhook->id();
        }

        return array_map(static fn (string $id): Uuid => $team[$id] ?? throw new InvalidNotificationSetting('A webhook of the team.'), $settings->webhookIds);
    }
}
