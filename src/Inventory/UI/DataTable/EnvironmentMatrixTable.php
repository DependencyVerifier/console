<?php

declare(strict_types=1);

namespace Inventory\UI\DataTable;

use Inventory\Application\Query\Team\GetEnvironmentMatrix;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The components of an environment and where each version runs; GetEnvironmentMatrix decides what the actor sees.
 */
final class EnvironmentMatrixTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'environmentId'])->setAllowedTypes('teamId', 'string')->setAllowedTypes('environmentId', 'string');
        $resolver->setDefaults(['driftOnly' => false])->setAllowedTypes('driftOnly', 'bool');
        /** @var array{teamId: string, environmentId: string, driftOnly: bool} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('components')
            ->add('component', TwigColumn::class, ['field' => 'component', 'label' => 'inventory.matrix.component', 'orderable' => false, 'template' => 'inventory/cells/matrix_component.html.twig'])
            ->add('version', TwigColumn::class, ['field' => 'version', 'label' => 'inventory.field.version', 'orderable' => false, 'template' => 'inventory/cells/matrix_version.html.twig'])
            ->add('where', TwigColumn::class, ['field' => 'places', 'label' => 'inventory.matrix.where', 'orderable' => false, 'template' => 'inventory/cells/matrix_places.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): GetEnvironmentMatrix => new GetEnvironmentMatrix($o['teamId'], $o['environmentId'], $page, $o['driftOnly'])]);
    }
}
