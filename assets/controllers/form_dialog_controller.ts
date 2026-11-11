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

    /**
     * Enter submits the dialog's form wherever the focus is (even on the close button, which a dialog focuses while
     * its form is still loading), except where Enter means something else: a textarea, a button other than the
     * close one, an open autocomplete list, a link.
     */
    submitOnEnter(event: KeyboardEvent): void {
        const target = event.target instanceof HTMLElement ? event.target : null;
        const form = this.#frame()?.querySelector<HTMLFormElement>('form') ?? null;
        if (null === target || null === form || event.isComposing || event.shiftKey) {
            return;
        }

        const isCloseButton = 'dialog-close' === target.dataset.slot;
        const ownMeaning = target instanceof HTMLTextAreaElement
            || target instanceof HTMLAnchorElement
            || (target instanceof HTMLButtonElement && !isCloseButton)
            || null !== target.closest('.ts-wrapper.dropdown-active');
        if (ownMeaning) {
            return;
        }

        event.preventDefault();
        form.requestSubmit();
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
