<?php

declare(strict_types=1);

namespace Shared\UI\Command;

use Shared\Infrastructure\RateLimiting\DbalRateLimiterStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:rate-limiter:prune', description: 'Delete expired rate limiter states')]
final readonly class PruneRateLimiterCommand
{
    public function __construct(private DbalRateLimiterStorage $storage)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d expired limiter states deleted.', $this->storage->prune()));

        return 0;
    }
}
