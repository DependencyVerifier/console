<?php

declare(strict_types=1);

namespace Tests\Fixtures\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Tests\Fixtures\Doctrine\FixtureAggregate;

final readonly class FixtureCommandHandler implements CommandHandler
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(FixtureCommand $command): void
    {
        match ($command->behaviour) {
            'create' => $this->entityManager->persist(new FixtureAggregate($command->id, $command->name)),
            'rename' => $this->load($command->id)->rename($command->name),
            'touch' => $this->load($command->id)->touch(),
            'rename_then_fail' => $this->renameThenFail($command),
            'permission' => throw PermissionDenied::for('fixture.edit'),
            'not_found' => throw ResourceNotFound::of('Fixture'),
            'rule' => throw new FixtureRuleViolated(),
            'technical' => throw new \RuntimeException('Boom'),
            'lock' => $this->entityManager->getConnection()->fetchOne('SELECT id FROM fixture_aggregate WHERE id = :id FOR UPDATE', ['id' => $command->id]),
            default => null,
        };
    }

    private function renameThenFail(FixtureCommand $command): never
    {
        $this->load($command->id)->rename($command->name);
        $this->entityManager->flush();

        throw new \RuntimeException('Failed after flush');
    }

    private function load(string $id): FixtureAggregate
    {
        return $this->entityManager->find(FixtureAggregate::class, $id) ?? throw ResourceNotFound::of('Fixture');
    }
}
