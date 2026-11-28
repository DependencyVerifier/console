<?php

declare(strict_types=1);

namespace Instance\Application\Query\Update\Handler;

use Instance\Application\Port\CurrentBuild;
use Instance\Application\Port\LatestConsoleReleaseSource;
use Instance\Application\Query\Update\FindAvailableUpdate;
use Instance\Domain\Model\AvailableUpdate;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Security\CurrentActor;

/**
 * The only judge of the banner: the super admin flag (the same one as ROLE_SUPER_ADMIN, never a delegable instance
 * permission), checked before anything reaches upstream. A build without a version (`dev`) never asks upstream.
 */
final readonly class FindAvailableUpdateHandler implements QueryHandler
{
    public function __construct(
        private CurrentActor $currentActor,
        private CurrentBuild $currentBuild,
        private LatestConsoleReleaseSource $latestConsoleReleaseSource,
    ) {
    }

    public function __invoke(FindAvailableUpdate $query): ?AvailableUpdate
    {
        if (!$this->currentActor->isSuperAdmin()) {
            throw PermissionDenied::for('instance.super_admin');
        }

        $current = $this->currentBuild->label()->version();
        if (null === $current) {
            return null;
        }

        $release = $this->latestConsoleReleaseSource->latest();

        return null === $release ? null : AvailableUpdate::between($current, $release);
    }
}
