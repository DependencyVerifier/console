<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Query\Team\ListTeams;
use Organization\Application\Query\View\ProjectView;
use Organization\Application\Query\View\QuotaUsage;
use Organization\Application\Query\View\TeamView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Query\PageRequest;

final class ViewsTest extends TestCase
{
    #[Test]
    public function it_should_deny_any_gesture_it_does_not_list(): void
    {
        $team = new TeamView('t', 'Platform', 'platform', 'team_admin', true, [], ['team.edit' => true, 'team.audit.view' => false]);
        $project = new ProjectView('p', 't', 'Platform', 'Shop', 0, 3, ['project.edit' => true, 'project.delete' => false]);

        foreach ([$team, $project] as $view) {
            self::assertFalse($view->can('unknown.gesture'));
        }
        self::assertTrue($team->can('team.edit'));
        self::assertFalse($team->can('team.audit.view'));
        self::assertTrue($project->can('project.edit'));
        self::assertFalse($project->can('project.delete'));
    }

    #[Test]
    public function it_should_tell_a_gauge_close_to_or_at_its_limit(): void
    {
        $usages = [
            [new QuotaUsage('max_projects', 10, 7, true), false, false],
            [new QuotaUsage('max_projects', 10, 8, true), true, false],
            [new QuotaUsage('max_projects', 10, 10, true), true, true],
            [new QuotaUsage('max_projects', null, 500, true), false, false],
        ];

        foreach ($usages as [$usage, $warning, $reached]) {
            self::assertSame([$warning, $reached], [$usage->isWarning(), $usage->isReached()], $usage->usage.'/'.($usage->limit ?? '∞'));
        }
    }

    #[Test]
    public function it_should_list_teams_by_the_largest_page(): void
    {
        $page = new ListTeams()->page;

        self::assertSame([0, PageRequest::MAX_LIMIT], [$page->offset, $page->limit]);
    }
}
