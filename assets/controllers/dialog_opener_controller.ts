import { Controller } from '@hotwired/stimulus';

/**
 * A menu item opening a <twig:FormDialog> placed outside the menu: it clicks the dialog's trigger (hidden), which
 * opens the dialog and loads its form. The dialog stays outside the menu because the menu closes on any item click.
 */
export default class extends Controller<HTMLElement> {
    static values = { dialog: String };

    declare readonly dialogValue: string;

    open(): void {
        document.querySelector<HTMLElement>(`[data-dialog-trigger-for="${CSS.escape(this.dialogValue)}"]`)?.click();
    }
}
