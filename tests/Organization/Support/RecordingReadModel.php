<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\View\EnvironmentRow;
use Organization\Application\Query\View\InvitationRow;
use Organization\Application\Query\View\MemberRow;
use Organization\Application\Query\View\ProjectRow;
use Organization\Application\Query\View\TeamSummary;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * A read model that answers fixed pages and records the ids each handler let through.
 */
final class RecordingReadModel implements OrganizationReadModel
{
    /** @var list<array{string, mixed}> */
    public array $calls = [];

    /** @var list<object> rows answered by every listing, filtered by the expected row class */
    public array $items = [];

    public int $filtered = 0;

    public int $total = 0;

    public ?PendingInvitation $pending = null;

    public function __construct(public int $projects = 0, public int $members = 0, public int $invitations = 0)
    {
    }

    /**
     * @param list<object> $items
     */
    public function answer(array $items, int $filtered, int $total): void
    {
        [$this->items, $this->filtered, $this->total] = [$items, $filtered, $total];
    }

    public function teams(?array $teamIds, PageRequest $page): Page
    {
        $this->calls[] = ['teams', $teamIds];

        return $this->page(TeamSummary::class);
    }

    public function team(string $teamId): ?TeamSummary
    {
        return null;
    }

    public function teamNames(array $teamIds): array
    {
        $this->calls[] = ['teamNames', $teamIds];

        return array_intersect_key(['019a6b2c-2f00-7a11-8b22-3c44d55e6f01' => 'Platform'], array_flip($teamIds));
    }

    public function activeMembers(string $teamId): int
    {
        $this->calls[] = ['activeMembers', $teamId];

        return $this->members;
    }

    public function pendingInvitations(string $teamId, \DateTimeImmutable $now): int
    {
        $this->calls[] = ['pendingInvitations', [$teamId, $now->format(\DATE_ATOM)]];

        return $this->invitations;
    }

    public function projectCount(string $teamId): int
    {
        $this->calls[] = ['projectCount', $teamId];

        return $this->projects;
    }

    public function teamMembers(string $teamId, PageRequest $page, ?array $matchingUserIds = null): Page
    {
        $this->calls[] = null === $matchingUserIds ? ['teamMembers', $teamId] : ['teamMembers', [$teamId, $matchingUserIds]];

        return $this->page(MemberRow::class);
    }

    public function projectMembers(string $projectId, PageRequest $page, ?array $matchingUserIds = null): Page
    {
        $this->calls[] = null === $matchingUserIds ? ['projectMembers', $projectId] : ['projectMembers', [$projectId, $matchingUserIds]];

        return $this->page(MemberRow::class);
    }

    public function invitations(?string $teamId, ?string $projectId, PageRequest $page, \DateTimeImmutable $now): Page
    {
        $this->calls[] = null === $projectId ? ['invitations', [$teamId, $now->format(\DATE_ATOM)]] : ['invitations', [$teamId, $projectId, $now->format(\DATE_ATOM)]];

        return $this->page(InvitationRow::class);
    }

    public function projects(string $teamId, ?array $projectIds, PageRequest $page): Page
    {
        $this->calls[] = ['projects', [$teamId, $projectIds]];

        return $this->page(ProjectRow::class);
    }

    public function environments(string $projectId, PageRequest $page): Page
    {
        $this->calls[] = ['environments', $projectId];

        return $this->page(EnvironmentRow::class);
    }

    public function teamEnvironments(string $teamId, ?string $environmentId = null): array
    {
        $this->calls[] = ['teamEnvironments', [$teamId, $environmentId]];

        return array_values(array_filter($this->items, static fn (object $item): bool => $item instanceof TeamEnvironment));
    }

    public function pendingInvitation(?string $tokenHash, ?string $invitationId, \DateTimeImmutable $now): ?PendingInvitation
    {
        $this->calls[] = ['pendingInvitation', [$tokenHash, $invitationId, $now->format(\DATE_ATOM)]];

        return $this->pending;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return Page<T>
     */
    private function page(string $class): Page
    {
        $items = [];
        foreach ($this->items as $item) {
            if ($item instanceof $class) {
                $items[] = $item;
            }
        }

        return new Page($items, $this->filtered, $this->total);
    }
}
