<?php

declare(strict_types=1);

namespace Inventory\UI\DataTable;

use Inventory\Application\Query\Team\ListPackages;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Shared\Application\Query\PageRequest;
use Shared\UI\DataTable\QueryBusAdapter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The packages of an application, an image or a host, from its current SBOM (ListPackages judges the access).
 */
final class PackagesTable implements DataTableTypeInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function configure(DataTable $dataTable, array $options): void
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['teamId', 'of', 'id']);
        /** @var array{teamId: string, of: string, id: string} $o */
        $o = $resolver->resolve($options);

        $dataTable
            ->setName('packages')
            ->add('name', TwigColumn::class, ['field' => 'name', 'label' => 'inventory.field.package', 'orderable' => true, 'template' => 'inventory/cells/package_name.html.twig'])
            ->add('version', TwigColumn::class, ['field' => 'version', 'label' => 'inventory.field.version', 'orderable' => false, 'template' => 'inventory/cells/mono.html.twig'])
            ->add('type', TwigColumn::class, ['field' => 'type', 'label' => 'inventory.field.ecosystem', 'orderable' => true, 'template' => 'fleet/cells/text.html.twig'])
            ->add('dependency', TwigColumn::class, ['field' => 'dependency', 'label' => 'inventory.field.dependency', 'orderable' => false, 'template' => 'inventory/cells/dependency.html.twig'])
            ->createAdapter(QueryBusAdapter::class, ['query' => static fn (PageRequest $page): ListPackages => new ListPackages($o['teamId'], $o['of'], $o['id'], $page)]);
    }
}
