import { Controller } from '@hotwired/stimulus';
import DataTable, { type Api, type Config } from 'datatables.net-dt';

interface ColumnMeta {
    name: string;
    label: string;
    orderable: boolean;
    className: string;
}

/**
 * Server-side DataTable (omines/datatables-bundle protocol): each draw POSTs the DataTables parameters plus `_dt`
 * (the table name) to the page URL; the controller answers JSON through the query bus. No inline script, no state
 * persisted by the library. The table reloads after every Turbo Stream (an action of the page succeeded or failed).
 */
export default class extends Controller<HTMLElement> {
    static targets = ['table', 'header'];

    static values = {
        name: String,
        url: String,
        columns: Array,
        language: Object,
        pageLength: Number,
    };

    declare readonly tableTarget: HTMLTableElement;
    declare readonly headerTarget: HTMLElement;
    declare readonly nameValue: string;
    declare readonly urlValue: string;
    declare readonly columnsValue: ColumnMeta[];
    declare readonly languageValue: Record<string, string>;
    declare readonly pageLengthValue: number;

    #table: Api | null = null;
    readonly #reload = (): void => {
        window.setTimeout(() => this.#table?.ajax.reload(undefined, false), 0);
    };

    connect(): void {
        const config: Config = {
            serverSide: true,
            processing: true,
            autoWidth: false,
            pageLength: this.pageLengthValue,
            order: [],
            // One header line: the card title and actions (rendered by Twig), then the search; info and paging below.
            layout: {
                topStart: this.headerTarget,
                topEnd: 'search',
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            drawCallback: () => this.#markSize(),
            language: this.languageValue,
            columns: this.columnsValue.map((column) => ({
                data: column.name,
                name: column.name,
                orderable: column.orderable,
                className: column.className,
            })),
            ajax: {
                url: this.urlValue,
                type: 'POST',
                data: (data: object) => ({ ...data, _dt: this.nameValue }),
            },
        };

        this.#table = new DataTable(this.tableTarget, config);
        document.addEventListener('turbo:before-stream-render', this.#reload);
    }

    /** `data-single-page`: everything fits on one page, the footer (info, paging) hides. */
    #markSize(): void {
        if (null === this.#table) {
            return;
        }

        this.element.toggleAttribute('data-single-page', this.#table.page.info().pages <= 1);
    }

    disconnect(): void {
        document.removeEventListener('turbo:before-stream-render', this.#reload);
        this.#table?.destroy();
        this.#table = null;
    }
}
