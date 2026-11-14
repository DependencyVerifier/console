<?php

declare(strict_types=1);

namespace Fleet\UI\DataTable;

use Fleet\Application\Query\Team\ListEnrollmentTokens;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\DataTableView;
use Shared\UI\DataTable\QueryBusAdapter;

/**
 * The query handler decides what the actor may list; cells are Twig templates (autoescaped).
 */
final class TokensTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $teamId = $options['teamId'] ?? null;
        if (!\is_string($teamId)) {
            throw new \InvalidArgumentException('Option "teamId" is required.');
        }

        $dataTable
            ->setName('tokens')
            ->add('kind', TwigColumn::class, ['field' => 'kind', 'label' => 'fleet.field.kind', 'orderable' => false, 'template' => 'fleet/cells/token_kind.html.twig'])
            ->add('environment', TwigColumn::class, ['field' => 'environment', 'label' => 'fleet.field.environment', 'orderable' => false, 'template' => 'fleet/cells/text.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('uses', TwigColumn::class, ['field' => 'uses', 'label' => 'fleet.field.uses', 'orderable' => false, 'template' => 'fleet/cells/token_uses.html.twig', 'className' => DataTableView::SECONDARY])
            ->add('expires', TwigColumn::class, ['field' => 'expiresAt', 'label' => 'fleet.field.expires', 'orderable' => true, 'template' => 'fleet/cells/time.html.twig'])
            ->add('status', TwigColumn::class, ['field' => 'status', 'label' => 'fleet.field.status', 'orderable' => false, 'template' => 'fleet/cells/token_status.html.twig'])
            ->add('actions', TwigColumn::class, ['field' => 'id', 'label' => 'admin.users.actions', 'orderable' => false, 'template' => 'fleet/cells/token_actions.html.twig', 'className' => 'text-end'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListEnrollmentTokens => new ListEnrollmentTokens($teamId, $page)]);
    }
}
