<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Refresh\Handler;

use Exposure\Application\Command\Refresh\RefreshFindings;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\SubjectPlacement;
use Exposure\Domain\Model\SubjectType;
use Exposure\Domain\Model\TeamExposure;
use Exposure\Domain\Repository\FindingRepository;
use Exposure\Domain\Repository\TeamExposureRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Inventory\ListTeamSubjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Vulnerability\AssessSubjects;
use Shared\Application\Contract\Vulnerability\Risk;
use Symfony\Component\Uid\Uuid;

final readonly class RefreshFindingsHandler implements CommandHandler
{
    public function __construct(
        private QueryBus $queries,
        private FindingRepository $findings,
        private TeamExposureRepository $exposures,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RefreshFindings $command): void
    {
        $now = $this->clock->now();
        $team = Uuid::fromString($command->teamId);
        $exposure = $this->exposures->find($team);
        if (null === $exposure) {
            $exposure = TeamExposure::of($team);
            $this->exposures->add($exposure);
        }

        $exposure->refreshing($now);
        $subjects = $this->queries->ask(new ListTeamSubjects($command->teamId));
        $risks = $this->queries->ask(new AssessSubjects($subjects));

        $existing = [];
        foreach ($this->findings->ofTeam($team) as $finding) {
            $existing[$finding->identity()] = $finding;
        }

        $environments = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($command->teamId)) as $environment) {
            $environments[$environment->environmentId] = $environment;
        }

        // A host is in production when it carries an application attached to a production environment.
        $productionHosts = [];
        foreach ($subjects as $subject) {
            if (null !== $subject->hostId && ($environments[$subject->environmentId ?? '']->production ?? false)) {
                $productionHosts[$subject->hostId] = true;
            }
        }

        foreach ($subjects as $subject) {
            $type = SubjectType::from($subject->type);
            $environment = $environments[$subject->environmentId ?? ''] ?? null;
            $placement = new SubjectPlacement(
                $subject->name,
                null === $environment ? null : Uuid::fromString($environment->environmentId),
                null === $environment ? null : Uuid::fromString($environment->projectId),
                null === $subject->hostId ? null : Uuid::fromString($subject->hostId),
                $subject->hostName,
                SubjectType::Host === $type ? isset($productionHosts[$subject->id]) : ($environment->production ?? false),
            );
            foreach ($risks[$subject->key()] ?? [] as $risk) {
                $facts = self::facts($risk);
                $identity = Finding::identityOf($type, $subject->id, $facts->kind, $facts->key);
                $finding = $existing[$identity] ?? null;
                unset($existing[$identity]);
                if (null === $finding) {
                    $finding = Finding::detect(Uuid::v7(), $team, $type, Uuid::fromString($subject->id), $facts, $now);
                    $this->findings->add($finding);
                } else {
                    $finding->observe($facts, $now);
                }

                $finding->place($placement);
            }
        }

        foreach ($existing as $finding) {
            $finding->resolve($now);
        }
    }

    private static function facts(Risk $risk): FindingFacts
    {
        return new FindingFacts(
            FindingKind::from($risk->kind), $risk->key, $risk->title, $risk->package, $risk->installed, $risk->fixed, $risk->severity, $risk->cvss,
            $risk->knownExploited, $risk->epss, $risk->eolLevel, $risk->endOfLife, $risk->latest, $risk->aliases, $risk->suggested,
        );
    }
}
