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
    static targets = ['table'];

    static values = {
        name: String,
        url: String,
        columns: Array,
        language: Object,
        pageLength: Number,
    };

    declare readonly tableTarget: HTMLTableElement;
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
            lengthMenu: [10, 25, 50, 100],
            order: [],
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

    disconnect(): void {
        document.removeEventListener('turbo:before-stream-render', this.#reload);
        this.#table?.destroy();
        this.#table = null;
    }
}
