import { Controller } from '@hotwired/stimulus';

type State = 'open' | 'closed';

// From the Symfony UX Toolkit shadcn kit (DropdownMenu sub-menu), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'content'];

    static values = {
        openDelay: { type: Number, default: 0 },
        closeDelay: { type: Number, default: 0 },
    };

    declare readonly hasTriggerTarget: boolean;
    declare readonly triggerTarget: HTMLElement;
    declare readonly hasContentTarget: boolean;
    declare readonly contentTarget: HTMLElement;
    declare readonly openDelayValue: number;
    declare readonly closeDelayValue: number;

    #openTimeout: ReturnType<typeof setTimeout> | null = null;
    #closeTimeout: ReturnType<typeof setTimeout> | null = null;

    connect(): void {
        this.#setState('closed');
    }

    disconnect(): void {
        this.#clearTimeouts();
    }

    show(): void {
        this.#clearTimeouts();
        this.#openTimeout = setTimeout(() => {
            this.#setState('open');
            this.#openTimeout = null;
        }, this.openDelayValue);
    }

    hide(): void {
        this.#clearTimeouts();
        this.#closeTimeout = setTimeout(() => {
            this.#setState('closed');
            this.#closeTimeout = null;
        }, this.closeDelayValue);
    }

    // State lives on the trigger and content directly (not a parent group) so nested
    // sub-menus each react only to their own state.
    #setState(state: State): void {
        this.element.dataset.state = state;
        if (this.hasTriggerTarget) {
            this.triggerTarget.dataset.state = state;
        }
        if (this.hasContentTarget) {
            this.contentTarget.dataset.state = state;
        }
    }

    #clearTimeouts(): void {
        if (null !== this.#openTimeout) {
            clearTimeout(this.#openTimeout);
            this.#openTimeout = null;
        }
        if (null !== this.#closeTimeout) {
            clearTimeout(this.#closeTimeout);
            this.#closeTimeout = null;
        }
    }
}
