<?php

declare(strict_types=1);

namespace Shared\UI\DataTable;

use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the Stimulus controller needs to render a table, as data attributes (no inline script, CSP).
 */
final readonly class DataTableView
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return list<array{name: string, label: string, orderable: bool, className: string}>
     */
    public function columns(DataTable $table): array
    {
        return array_values(array_map(fn (AbstractColumn $column): array => [
            'name' => $column->getName(),
            'label' => $this->translator->trans($column->getLabel()),
            'orderable' => $column->isOrderable(),
            'className' => (string) $column->getClassName(),
        ], array_filter($table->getColumns(), static fn (AbstractColumn $column): bool => $column->isVisible())));
    }

    /**
     * @return array<string, string>
     */
    public function language(): array
    {
        $keys = ['processing', 'search', 'searchPlaceholder', 'lengthMenu', 'info', 'infoEmpty', 'infoFiltered', 'loadingRecords', 'zeroRecords', 'emptyTable'];
        $language = [];
        foreach ($keys as $key) {
            $language[$key] = $this->translator->trans('datatable.'.$key);
        }

        return $language;
    }
}
