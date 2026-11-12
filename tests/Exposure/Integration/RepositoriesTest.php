<?php

declare(strict_types=1);

namespace Tests\Exposure\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\FindingFacts;
use Exposure\Domain\Model\FindingKind;
use Exposure\Domain\Model\SubjectType;
use Exposure\Domain\Model\TeamExposure;
use Exposure\Infrastructure\Doctrine\DoctrineFindingRepository;
use Exposure\Infrastructure\Doctrine\DoctrineTeamExposureRepository;
use Exposure\Infrastructure\Doctrine\ExposureManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The Exposure tables against PostgreSQL: one finding per identity, read back whole, erased with their team.
 */
final class RepositoriesTest extends KernelTestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';

    private ExposureManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new ExposureManager(self::getContainer()->get('doctrine'));
    }

    #[Test]
    public function it_should_keep_one_finding_per_identity_and_erase_a_team(): void
    {
        $findings = new DoctrineFindingRepository($this->manager);
        $exposures = new DoctrineTeamExposureRepository($this->manager);
        $now = new \DateTimeImmutable('2026-11-12 08:00:00');
        $facts = new FindingFacts(FindingKind::EndOfLife, 'php 8.2', 'php 8.2', 'php', '8.2.26', null, null, null, false, null, 'soon', new \DateTimeImmutable('2026-12-31'), '8.2.35', []);
        $subject = Uuid::v7();
        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::TEAM), SubjectType::Host, $subject, $facts, $now));
        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::OTHER), SubjectType::Host, $subject, $facts, $now));
        $exposures->add(TeamExposure::of(Uuid::fromString(self::TEAM)));
        $exposures->add(TeamExposure::of(Uuid::fromString(self::OTHER)));
        $this->flush();

        $read = $findings->ofTeam(Uuid::fromString(self::TEAM));
        self::assertCount(1, $read);
        self::assertEquals($facts, $read[0]->facts(), 'Read back whole.');
        self::assertNotNull($exposures->find(Uuid::fromString(self::TEAM)));

        $findings->deleteTeam(Uuid::fromString(self::TEAM));
        $exposures->deleteTeam(Uuid::fromString(self::TEAM));
        $this->flush();
        self::assertSame([], $findings->ofTeam(Uuid::fromString(self::TEAM)));
        self::assertNull($exposures->find(Uuid::fromString(self::TEAM)));
        self::assertCount(1, $findings->ofTeam(Uuid::fromString(self::OTHER)), 'Another team keeps its findings.');

        $findings->add(Finding::detect(Uuid::v7(), Uuid::fromString(self::OTHER), SubjectType::Host, $subject, $facts, $now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    private function flush(): void
    {
        $this->manager->get()->flush();
        $this->manager->get()->clear();
    }
}
