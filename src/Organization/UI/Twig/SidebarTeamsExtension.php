<?php

declare(strict_types=1);

namespace Organization\UI\Twig;

use Organization\Application\Query\Team\ListTeams;
use Organization\Application\Query\View\TeamSummary;
use Organization\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Query\PageRequest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Teams of the signed-in user for the sidebar switcher (ListTeams: the super admin sees every team).
 */
final readonly class SidebarTeamsExtension
{
    public function __construct(private QueryBus $queries, private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    /**
     * @return list<array{id: string, name: string, logo: string, role: string, url: string}>
     */
    #[AsTwigFunction('sidebar_teams')]
    public function teams(): array
    {
        $page = $this->queries->ask(new ListTeams(new PageRequest(0, PageRequest::MAX_LIMIT)));

        return array_map(fn (TeamSummary $team): array => [
            'id' => $team->id,
            'name' => $team->name,
            'logo' => 'lucide:users',
            'role' => null === $team->role ? '' : $this->translator->trans('role.'.$team->role),
            'url' => $this->urls->generate(RouteCollection::TEAM->prefixed(), ['teamId' => $team->id]),
        ], $page->items);
    }
}
