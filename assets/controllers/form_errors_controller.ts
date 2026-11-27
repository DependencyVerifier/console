import { Controller } from '@hotwired/stimulus';

const FIELD = 'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])';

/**
 * Pages answering a 422 (forms guide: "move focus to the first invalid field"): the forms of these pages are
 * `novalidate` and judged by the server, so the page comes back with its errors and nothing focused. On connect, the
 * first invalid field takes the focus; its aria-describedby already names the error. Form dialogs do the same in
 * form_dialog_controller.ts.
 */
export default class extends Controller<HTMLElement> {
    connect(): void {
        const invalid = this.element.querySelector<HTMLElement>(`:is(${FIELD})[aria-invalid="true"]`);
        if (null !== invalid && !invalid.closest('dialog')) {
            invalid.focus();
        }
    }
}
