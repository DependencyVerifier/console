<?php

declare(strict_types=1);

namespace Notification\Application\Command\Notify\Handler;

use Notification\Application\Command\Notify\NotifyFindingChange;
use Notification\Application\Notice\NoticeWriter;
use Notification\Application\Notice\Occurrence;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationSubject;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Exposure\FindingChanged;
use Shared\Application\Contract\Exposure\FindingDigest;
use Shared\Application\Contract\Exposure\GetFindingDigests;

/**
 * What a change of a finding tells: a vulnerability opened (again) or fixed, an end of life reached or coming closer,
 * a version behind. A finding whose risk is accepted is silent (cadrage §8), and so is a finding gone meanwhile.
 */
final readonly class NotifyFindingChangeHandler implements CommandHandler
{
    public function __construct(private QueryBus $queries, private NoticeWriter $writer)
    {
    }

    public function __invoke(NotifyFindingChange $command): void
    {
        $digest = $this->queries->ask(new GetFindingDigests($command->teamId, [$command->findingId]))[0] ?? null;
        $event = null === $digest || $digest->accepted ? null : self::event($digest->kind, $command->change);
        if (null === $digest || null === $event) {
            return;
        }

        $this->writer->write(new Occurrence(
            $command->teamId,
            new NotificationSubject($event, $digest->projectId, $digest->environmentId, $digest->severity, $digest->cvss, $digest->knownExploited, $digest->epss),
            \sprintf('finding:%s:%s:%s', $command->findingId, $command->change, $command->at),
            self::content($digest, $command->change),
        ));
    }

    private static function event(string $kind, string $change): ?NotificationEvent
    {
        $fixed = FindingChanged::FIXED === $change;

        return match ($kind) {
            'vulnerability' => $fixed ? NotificationEvent::VulnerabilityFixed : NotificationEvent::VulnerabilityOpened,
            'eol' => $fixed ? null : NotificationEvent::EndOfLife,
            'outdated' => $fixed || FindingChanged::ESCALATED === $change ? null : NotificationEvent::Outdated,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(FindingDigest $digest, string $change): array
    {
        return [
            'change' => $change,
            'finding' => [
                'id' => $digest->id,
                'kind' => $digest->kind,
                'title' => $digest->title,
                'package' => $digest->package,
                'installed' => $digest->installed,
                'fixed' => $digest->fixed,
                'severity' => $digest->severity,
                'cvss' => $digest->cvss,
                'known_exploited' => $digest->knownExploited,
                'epss' => $digest->epss,
                'eol_level' => $digest->eolLevel,
                'end_of_life' => $digest->endOfLife?->format('Y-m-d'),
                'suggested' => $digest->suggested,
                'latest' => $digest->latest,
                'aliases' => $digest->aliases,
            ],
            'subject' => ['type' => $digest->subjectType, 'id' => $digest->subjectId, 'name' => $digest->subjectName],
            'project_id' => $digest->projectId,
            'environment_id' => $digest->environmentId,
        ];
    }
}
