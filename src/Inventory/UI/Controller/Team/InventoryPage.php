<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Omines\DataTablesBundle\DataTable;
use Organization\UI\Controller\Team\TeamPage;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The inventory pages of a team (Applications, an application, a host, an image), in the team frame of Organization.
 * GetTeam judges the scope (404); the Inventory queries judge what the actor sees in it.
 */
final readonly class InventoryPage
{
    public function __construct(private TeamPage $teams, private Environment $twig)
    {
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array<string, mixed>     $context
     */
    public function render(string $teamId, string $template, string $navActive, array $tables = [], array $context = []): Response
    {
        $team = $this->teams->team($teamId);
        foreach ($tables as $table) {
            if ($table->isCallback()) {
                return $table->getResponse();
            }
        }

        return new Response($this->twig->render('inventory/team/'.$template.'.html.twig', [
            ...$context,
            'team' => $team,
            'tab' => 'applications',
            'tabs' => TeamPage::tabsOf($team),
            'tables' => $tables,
            'nav_active' => $navActive,
            'current_team' => $team->id,
        ]));
    }
}
