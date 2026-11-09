<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Admin;

use Organization\Application\Command\Instance\UpdateDefaultQuotas;
use Organization\Application\Query\Instance\GetDefaultQuotas;
use Organization\UI\Controller\RouteCollection;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Default quotas, copied into the teams created afterwards (existing teams keep theirs).
 */
#[Route(path: '/admin/settings/quotas', name: RouteCollection::ADMIN_DEFAULT_QUOTAS->value, methods: ['GET', 'POST'])]
final readonly class AdminDefaultQuotasController
{
    public function __construct(private QueryBus $queries, private QuotasPage $page, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->page->handle(
            $request,
            'default_quotas',
            $this->queries->ask(new GetDefaultQuotas()),
            static fn (array $values): UpdateDefaultQuotas => new UpdateDefaultQuotas($values),
            $this->urls->generate(RouteCollection::ADMIN_DEFAULT_QUOTAS->prefixed()),
            ['title' => null, 'subtitle_key' => 'organization.quotas.defaults'],
        );
    }
}
