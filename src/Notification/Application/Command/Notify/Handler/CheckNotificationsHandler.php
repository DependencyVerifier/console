<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify\Handler;

use Notification\Application\Command\Notify\CheckNotifications;
use Notification\Application\Notice\NoticeWriter;
use Notification\Application\Notice\Occurrence;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationSubject;
use Notification\Domain\Repository\NotificationRuleRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Exposure\GetFindingDigests;
use Shared\Application\Contract\Exposure\ListExpiringAcceptances;
use Shared\Application\Contract\Fleet\ListSilentAgents;

final readonly class CheckNotificationsHandler implements CommandHandler
{
    public const int EXPIRING_WITHIN_DAYS = 7;

    public function __construct(private NotificationRuleRepository $rules, private QueryBus $queries, private NoticeWriter $writer, private ClockInterface $clock)
    {
    }

    public function __invoke(CheckNotifications $command): void
    {
        $now = $this->clock->now();
        $this->silentAgents($now);
        $this->expiringAcceptances($now);
    }

    /**
     * Silent for at least the threshold of a rule: told once per silence (the key holds the last contact).
     */
    private function silentAgents(\DateTimeImmutable $now): void
    {
        $teams = [];
        $thresholds = [];
        foreach ($this->rules->tellingSilentAgents() as $rule) {
            $teams[] = $rule->teamId()->toRfc4122();
            $thresholds[] = $rule->thresholds()->silentHours;
        }

        if ([] === $thresholds) {
            return;
        }

        // Since the shortest threshold; each rule then judges the silence against its own.
        foreach ($this->queries->ask(new ListSilentAgents($now->modify(\sprintf('-%d hours', min($thresholds))))) as $agent) {
            if (\in_array($agent->teamId, $teams, true)) {
                $this->writer->write(new Occurrence(
                    $agent->teamId,
                    new NotificationSubject(NotificationEvent::AgentSilent, silentHours: intdiv($now->getTimestamp() - $agent->lastSeenAt->getTimestamp(), 3600)),
                    \sprintf('agent-silent:%s:%s', $agent->agentId, $agent->lastSeenAt->format(\DATE_ATOM)),
                    ['agent' => ['id' => $agent->agentId, 'hostname' => $agent->hostname, 'last_seen_at' => $agent->lastSeenAt->format(\DATE_ATOM)]],
                ));
            }
        }
    }

    private function expiringAcceptances(\DateTimeImmutable $now): void
    {
        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        foreach ($this->queries->ask(new ListExpiringAcceptances($today, $today->modify(\sprintf('+%d days', self::EXPIRING_WITHIN_DAYS)))) as $acceptance) {
            $digest = $this->queries->ask(new GetFindingDigests($acceptance->teamId, [$acceptance->findingId]))[0] ?? null;
            if (null === $digest) {
                continue;
            }

            $this->writer->write(new Occurrence(
                $acceptance->teamId,
                new NotificationSubject(NotificationEvent::AcceptanceExpiring, $digest->projectId, $digest->environmentId),
                'acceptance-expiring:'.$acceptance->acceptanceId,
                [
                    'acceptance' => ['id' => $acceptance->acceptanceId, 'expires_on' => $acceptance->expiresOn->format('Y-m-d')],
                    'finding' => ['id' => $digest->id, 'kind' => $digest->kind, 'title' => $digest->title, 'package' => $digest->package],
                    'subject' => ['type' => $digest->subjectType, 'id' => $digest->subjectId, 'name' => $digest->subjectName],
                    'project_id' => $digest->projectId,
                    'environment_id' => $digest->environmentId,
                ],
            ));
        }
    }
}
