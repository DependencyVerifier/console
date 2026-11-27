import { Controller } from '@hotwired/stimulus';

type ReloadableFrame = HTMLElement & { reload(): Promise<void> };
type Closable = { close(): void };

/**
 * The generic form dialog (<twig:FormDialog>): its form comes from its own endpoint into a lazy Turbo Frame.
 * - The first opening makes the frame visible, which loads it; every later opening reloads it, so the CSRF token
 *   is fresh and the errors of a previous attempt are gone.
 * - While the form loads, the focus is on the dialog's title (never on the close button, where Enter would close it);
 *   once the form is in, it goes to the first invalid field after a 422, else to the first field.
 * - A successful submission answered by a Turbo Stream closes the dialog; a 422 or a refusal stays in it, and so does
 *   a success answered in the frame itself (a secret shown once, closed by the user).
 */
export default class extends Controller<HTMLElement> {
    #opened = false;

    load(): void {
        const frame = this.#frame();
        if (this.#opened && null !== frame) {
            void frame.reload();
        }

        this.#opened = true;
        if (null === this.#field()) {
            this.element.querySelector<HTMLElement>('[data-slot="dialog-title"]')?.focus();
        }
    }

    focus(): void {
        this.#field()?.focus();
    }

    /**
     * Enter submits the dialog's form wherever the focus is (the title, a field), except where Enter means something
     * else: a textarea, a button (the close one included), an open autocomplete list, a link.
     */
    submitOnEnter(event: KeyboardEvent): void {
        const target = event.target instanceof HTMLElement ? event.target : null;
        const form = this.#frame()?.querySelector<HTMLFormElement>('form') ?? null;
        if (null === target || null === form || event.isComposing || event.shiftKey) {
            return;
        }

        const ownMeaning = target instanceof HTMLTextAreaElement
            || target instanceof HTMLAnchorElement
            || target instanceof HTMLButtonElement
            || null !== target.closest('.ts-wrapper.dropdown-active');
        if (ownMeaning) {
            return;
        }

        event.preventDefault();
        form.requestSubmit();
    }

    submitted(event: CustomEvent<{ success: boolean; fetchResponse?: { response: Response } }>): void {
        const contentType = event.detail.fetchResponse?.response.headers.get('Content-Type') ?? '';
        if (!event.detail.success || !contentType.startsWith('text/vnd.turbo-stream.html')) {
            return;
        }

        const dialog = this.application.getControllerForElementAndIdentifier(this.element, 'dialog') as Closable | null;
        dialog?.close();
    }

    #field(): HTMLElement | null {
        const frame = this.#frame();
        const fields = 'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])';

        return frame?.querySelector<HTMLElement>(`:is(${fields})[aria-invalid="true"]`) ?? frame?.querySelector<HTMLElement>(fields) ?? null;
    }

    #frame(): ReloadableFrame | null {
        return this.element.querySelector<ReloadableFrame>('turbo-frame');
    }
}
