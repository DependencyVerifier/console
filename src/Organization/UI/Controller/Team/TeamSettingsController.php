<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Organization\UI\Controller\RouteCollection;
use Organization\UI\Form\NameType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Settings tab (`team.edit`): the name; the slug and the id read-only.
 */
#[Route(path: '/teams/{teamId}/settings', name: RouteCollection::TEAM_SETTINGS->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET'])]
final readonly class TeamSettingsController
{
    public function __construct(private TeamPage $page, private FormFactoryInterface $forms)
    {
    }

    public function __invoke(string $teamId): Response
    {
        $team = $this->page->team($teamId);
        $this->page->assertOpen($team, TeamPage::SETTINGS);
        $rename = $this->forms->createNamed('team_name', NameType::class, ['name' => $team->name], ['csrf_token_id' => 'team_rename:'.$teamId, 'label_key' => 'organization.field.team_name']);

        return $this->page->render($team, TeamPage::SETTINGS, context: ['rename_form' => $rename->createView()]);
    }
}
