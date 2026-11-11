<?php

declare(strict_types=1);

namespace Fleet\UI\Controller\Team;

use Fleet\Application\Query\Team\GetTeamFleet;
use Fleet\Application\Query\View\FleetView;
use Omines\DataTablesBundle\DataTable;
use Organization\Application\Query\View\TeamView;
use Organization\UI\Controller\Team\TeamPage;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The three fleet sections of a team (Hosts, Agents, Enrollment), in the team frame of Organization (same header and
 * sidebar group). GetTeam judges the scope (404), GetTeamFleet the access (403 for a TEAM_GUEST) and the gestures.
 */
final readonly class FleetPage
{
    public const string HOSTS = 'hosts';
    public const string AGENTS = 'agents';
    public const string ENROLLMENT = 'enrollment';

    /** Request attribute holding the FleetView, read by the action cells (also during a DataTable callback). */
    public const string ATTRIBUTE = '_fleet';

    public function __construct(private TeamPage $teams, private QueryBus $queries, private Environment $twig, private RequestStack $requests)
    {
    }

    public function team(string $teamId): TeamView
    {
        return $this->teams->team($teamId);
    }

    public function fleet(string $teamId): FleetView
    {
        return $this->queries->ask(new GetTeamFleet($teamId));
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array<string, mixed>     $context
     * @param string|null              $template the template under fleet/team/ (default: the section's)
     */
    public function render(string $teamId, string $section, array $tables = [], array $context = [], ?string $template = null): Response
    {
        $team = $this->team($teamId);
        $fleet = $this->fleet($teamId);
        $this->requests->getCurrentRequest()?->attributes->set(self::ATTRIBUTE, $fleet);
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return new Response($this->twig->render(\sprintf('fleet/team/%s.html.twig', $template ?? $section), [
            ...$context,
            'team' => $team,
            'fleet' => $fleet,
            'tab' => $section,
            'tabs' => TeamPage::tabsOf($team),
            'tables' => $tables,
            'nav_active' => 'team_'.$section,
            'current_team' => $team->id,
        ]));
    }
}
