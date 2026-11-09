import { Controller } from '@hotwired/stimulus';

type ReloadableFrame = HTMLElement & { reload(): Promise<void> };
type Closable = { close(): void };

/**
 * The generic form dialog (<twig:FormDialog>): its form comes from its own endpoint into a lazy Turbo Frame.
 * - The first opening makes the frame visible, which loads it; every later opening reloads it, so the CSRF token
 *   is fresh and the errors of a previous attempt are gone.
 * - The first field gets the focus once the form is in.
 * - A successful submission (the page answered a Turbo Stream) closes the dialog; a 422 or a refusal stays in it.
 */
export default class extends Controller<HTMLElement> {
    #opened = false;

    load(): void {
        const frame = this.#frame();
        if (this.#opened && null !== frame) {
            void frame.reload();
        }

        this.#opened = true;
    }

    focus(): void {
        this.#frame()
            ?.querySelector<HTMLElement>('input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])')
            ?.focus();
    }

    submitted(event: CustomEvent<{ success: boolean }>): void {
        if (!event.detail.success) {
            return;
        }

        const dialog = this.application.getControllerForElementAndIdentifier(this.element, 'dialog') as Closable | null;
        dialog?.close();
    }

    #frame(): ReloadableFrame | null {
        return this.element.querySelector<ReloadableFrame>('turbo-frame');
    }
}
