<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Omines\DataTablesBundle\DataTable;
use Organization\Application\Query\Team\GetTeam;
use Organization\Application\Query\View\TeamView;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * The four tabs of a team page share their header and their tab bar: GetTeam is the judge of the scope (404), the
 * tab of the access (403, audited by RefusalExceptionListener); each tab answers the callbacks of its own DataTables.
 */
final readonly class TeamPage
{
    public const string OVERVIEW = 'overview';
    public const string PROJECTS = 'projects';
    public const string MEMBERS = 'members';
    public const string SETTINGS = 'settings';

    public function __construct(private QueryBus $queries, private Environment $twig)
    {
    }

    public function team(string $teamId): TeamView
    {
        return $this->queries->ask(new GetTeam($teamId));
    }

    /**
     * The tabs the actor may open, in their order.
     *
     * @return list<string>
     */
    public static function tabsOf(TeamView $team): array
    {
        return array_values(array_filter([
            self::OVERVIEW,
            self::PROJECTS,
            'team_guest' === $team->role ? null : self::MEMBERS,
            $team->can('team.edit') ? self::SETTINGS : null,
        ]));
    }

    public function assertOpen(TeamView $team, string $tab): void
    {
        if (!\in_array($tab, self::tabsOf($team), true)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array<string, mixed>     $context
     */
    public function render(TeamView $team, string $tab, array $tables = [], array $context = []): Response
    {
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return new Response($this->twig->render(\sprintf('organization/team/%s.html.twig', $tab), [
            ...$context,
            'team' => $team,
            'tab' => $tab,
            'tabs' => self::tabsOf($team),
            'tables' => $tables,
            'nav_active' => 'team',
            'current_team' => $team->id,
        ]));
    }
}
