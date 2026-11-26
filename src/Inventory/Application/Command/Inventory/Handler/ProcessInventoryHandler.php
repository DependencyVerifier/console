<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Inventory\Handler;

use Inventory\Application\Command\Inventory\ProcessInventory;
use Inventory\Application\Protocol\CycloneDxReader;
use Inventory\Application\Protocol\SnapshotDecoder;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use Inventory\Domain\Repository\AppInstanceRepository;
use Inventory\Domain\Repository\ContainerImageRepository;
use Inventory\Domain\Repository\HostInventoryRepository;
use Inventory\Domain\Repository\InventoryRunRepository;
use Inventory\Domain\Repository\PackageRepository;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Inventory\Domain\Repository\SnapshotRecordRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Fleet\GetAgentContext;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Symfony\Component\Uid\Uuid;

/**
 * The processing of a completed inventory (decisions of step 3):
 * - host facts and OS packages of the host;
 * - applications found or followed, by (host, subject_key); those the same agent no longer reports become `gone`;
 * - container images, once per team and image id;
 * - the packages of each SBOM, extracted once per stored snapshot;
 * - automatic attachment: the `dev.depverify.environment` label when it names an environment of the team, else the
 *   default environment of the agent's token when the host carries a single application; never over a human's
 *   choice;
 * - one history entry per subject.
 * A snapshot whose document cannot be read is skipped (its subject keeps its previous state).
 */
final readonly class ProcessInventoryHandler implements CommandHandler
{
    public function __construct(
        private InventoryRunRepository $runs,
        private SnapshotBlobRepository $blobs,
        private HostInventoryRepository $hosts,
        private AppInstanceRepository $apps,
        private ContainerImageRepository $images,
        private SnapshotRecordRepository $records,
        private PackageRepository $packages,
        private QueryBus $queries,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessInventory $command): void
    {
        $run = Uuid::isValid($command->inventoryId) ? $this->runs->find(Uuid::fromString($command->inventoryId)) : null;
        if (null === $run || RunStatus::Completed !== $run->status()) {
            return;
        }

        $now = $this->clock->now();
        $manifest = $run->manifest();
        $this->recordHost($run, $manifest->hostFacts->digest, $now);

        // Every application of the host, once (the ones discovered now are added to it: Doctrine does not query them).
        $onHost = [];
        foreach ($this->apps->ofHost($run->hostId()) as $app) {
            $onHost[$app->subjectKey()] = $app;
        }

        $reported = [];
        foreach ($manifest->subjects as $subject) {
            match (true) {
                $subject->kind->isApplication() => $reported[] = $onHost[$subject->key] = $this->recordApplication($run, $subject, $onHost[$subject->key] ?? null, $now),
                SubjectKind::ContainerImage === $subject->kind => $this->recordImage($run, $subject, $now),
                default => null,
            };
            $this->extractPackages($run->teamId(), $subject->sbom->digest);
        }

        $this->markGone($run, $onHost, $reported);
        $this->attach($run, $onHost, $reported);
        $run->markProcessed($now);
    }

    private function recordHost(InventoryRun $run, Digest $factsDigest, \DateTimeImmutable $now): void
    {
        $facts = $this->document($run->teamId(), $factsDigest, MediaType::HostFacts);
        if (null === $facts) {
            return;
        }

        $host = $this->hosts->find($run->hostId());
        if (null === $host) {
            $host = HostInventory::of($run->hostId(), $run->teamId());
            $this->hosts->add($host);
        }

        $packages = null;
        foreach ($run->manifest()->subjects as $subject) {
            if (SubjectKind::Host === $subject->kind) {
                $packages = $subject->sbom->digest;
                $this->record($run, SnapshotSubject::HostPackages, $run->hostId(), $packages, $now);
            }
        }

        $host->record($run->id(), $run->agentId(), array_intersect_key($facts, array_flip(['hostname', 'os', 'kernel', 'arch', 'runtimes', 'docker', 'agent'])), $factsDigest, $packages, $now);
        $this->record($run, SnapshotSubject::HostFacts, $run->hostId(), $factsDigest, $now);
    }

    private function recordApplication(InventoryRun $run, Subject $subject, ?AppInstance $app, \DateTimeImmutable $now): AppInstance
    {
        if (null === $app) {
            $app = AppInstance::discover(Uuid::v7(), $run->teamId(), $run->hostId(), $run->agentId(), $subject, $now);
            $this->apps->add($app);
        } else {
            $app->seen($subject, $run->agentId(), $now);
        }

        $this->record($run, SnapshotSubject::Application, $app->id(), $subject->sbom->digest, $now);

        return $app;
    }

    private function recordImage(InventoryRun $run, Subject $subject, \DateTimeImmutable $now): void
    {
        $image = $this->images->findByImageId($run->teamId(), (string) $subject->string('image_id'));
        if (null === $image) {
            $image = ContainerImage::discover(Uuid::v7(), $run->teamId(), $subject, $now);
            $this->images->add($image);
        } else {
            $image->seen($subject, $now);
        }

        $this->record($run, SnapshotSubject::Image, $image->id(), $subject->sbom->digest, $now);
    }

    private function extractPackages(Uuid $teamId, Digest $digest): void
    {
        $blob = $this->blobs->find($teamId, $digest);
        if (null === $blob || $this->packages->hasPackagesOf($blob->id())) {
            return;
        }

        $sbom = $this->document($teamId, $digest, MediaType::CycloneDx);
        if (null !== $sbom) {
            $this->packages->store($teamId, $blob->id(), CycloneDxReader::packages($sbom)['packages']);
        }
    }

    /**
     * @param array<string, AppInstance> $onHost
     * @param list<AppInstance>          $reported
     */
    private function markGone(InventoryRun $run, array $onHost, array $reported): void
    {
        foreach ($onHost as $app) {
            if ($app->isReportedBy($run->agentId()) && !\in_array($app, $reported, true)) {
                $app->markGone();
            }
        }
    }

    /**
     * @param array<string, AppInstance> $onHost
     * @param list<AppInstance>          $reported
     */
    private function attach(InventoryRun $run, array $onHost, array $reported): void
    {
        $default = $this->defaultEnvironmentOf($run);
        $single = 1 === \count(array_filter($onHost, static fn (AppInstance $a): bool => $a->isPresent()));
        foreach ($reported as $app) {
            $label = \is_array($app->fields()['labels'] ?? null) ? ($app->fields()['labels']['environment'] ?? null) : null;
            $environment = \is_string($label) ? $this->environmentOfTeam($run->teamId(), $label) : null;
            $environment ??= $single ? $default : null;
            if (null !== $environment) {
                $app->autoAssign($environment);
            }
        }
    }

    private function defaultEnvironmentOf(InventoryRun $run): ?Uuid
    {
        try {
            $environment = $this->queries->ask(new GetAgentContext($run->agentId()->toRfc4122()))->defaultEnvironmentId;
        } catch (ResourceNotFound) {
            return null; // the agent is gone (team deleted meanwhile)
        }

        return null === $environment ? null : $this->environmentOfTeam($run->teamId(), $environment);
    }

    private function environmentOfTeam(Uuid $teamId, string $environmentId): ?Uuid
    {
        $environment = $this->queries->ask(new FindTeamEnvironment($teamId->toRfc4122(), $environmentId));

        return null === $environment ? null : Uuid::fromString($environment->environmentId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(Uuid $teamId, Digest $digest, MediaType $mediaType): ?array
    {
        $blob = $this->blobs->find($teamId, $digest);
        if (null === $blob) {
            return null;
        }

        try {
            return SnapshotDecoder::document($blob->gzip(), $mediaType);
        } catch (InvalidInventory) {
            return null;
        }
    }

    private function record(InventoryRun $run, SnapshotSubject $type, Uuid $subjectId, Digest $digest, \DateTimeImmutable $now): void
    {
        $this->records->add(SnapshotRecord::take(Uuid::v7(), $run->teamId(), $run->id(), $type, $subjectId, $digest, $now));
    }
}
