import { Controller } from '@hotwired/stimulus';

/**
 * "Show password" toggle of a password field (form theme, sign-in page): switches the input between `password` and
 * `text`, the button telling its state through aria-pressed. The field goes back to `password` before its form is
 * sent, so the browser never stores it as plain text.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['input'];

    declare readonly inputTarget: HTMLInputElement;

    readonly #onSubmit = (): void => {
        this.#show(false);
    };

    connect(): void {
        this.inputTarget.form?.addEventListener('submit', this.#onSubmit);
    }

    disconnect(): void {
        this.inputTarget.form?.removeEventListener('submit', this.#onSubmit);
    }

    toggle(event: Event): void {
        this.#show('password' === this.inputTarget.type);
        if (event.currentTarget instanceof HTMLElement) {
            event.currentTarget.setAttribute('aria-pressed', String('text' === this.inputTarget.type));
        }
    }

    #show(visible: boolean): void {
        this.inputTarget.type = visible ? 'text' : 'password';
        if (!visible) {
            this.element.querySelector('[aria-pressed]')?.setAttribute('aria-pressed', 'false');
        }
    }
}
