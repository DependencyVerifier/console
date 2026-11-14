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
    /** The class of a column hidden on narrow screens (datatables.css): the table fits a phone without scrolling. */
    public const string SECONDARY = 'dt-secondary';

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
     * The texts of a table; an empty one says what this list is for and how it fills (`datatable.empty.<name>`, else the
     * generic sentence).
     *
     * @return array<string, string>
     */
    public function language(?string $name = null): array
    {
        $keys = ['processing', 'search', 'searchPlaceholder', 'lengthMenu', 'info', 'infoEmpty', 'infoFiltered', 'loadingRecords', 'zeroRecords', 'emptyTable'];
        $language = [];
        foreach ($keys as $key) {
            $language[$key] = $this->translator->trans('datatable.'.$key);
        }

        $key = 'datatable.empty.'.$name;
        $sentence = null === $name ? $key : $this->translator->trans($key);
        if ($sentence !== $key) {
            $language['emptyTable'] = $sentence;
        }

        return $language;
    }
}
