import { Controller } from '@hotwired/stimulus';

type State = 'open' | 'closed';

// From the Symfony UX Toolkit shadcn kit (DropdownMenu sub-menu), converted to TypeScript. Keyboard and touch added
// (WAI-ARIA menu pattern): Enter, Space or an arrow on the trigger opens it on its first item, Up/Down/Home/End move
// inside, Left, Right or Escape close it back to the trigger; a click toggles it. The parent menu never sees these keys.
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

    toggle(event: Event): void {
        event.preventDefault();
        if ('open' === this.element.dataset.state) {
            this.#setState('closed');
        } else {
            this.#open(true);
        }
    }

    onKeydown(event: KeyboardEvent): void {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const onTrigger = this.hasTriggerTarget && target === this.triggerTarget;
        if (onTrigger && ['Enter', ' ', 'ArrowRight', 'ArrowLeft'].includes(event.key)) {
            this.#consume(event);
            this.#open(true);

            return;
        }

        if (!this.hasContentTarget || !this.contentTarget.contains(target)) {
            return;
        }

        const items = this.#items();
        const index = items.indexOf(target);
        switch (event.key) {
            case 'ArrowDown':
                this.#consume(event);
                items[(index + 1) % items.length]?.focus();
                break;
            case 'ArrowUp':
                this.#consume(event);
                items[(index - 1 + items.length) % items.length]?.focus();
                break;
            case 'Home':
                this.#consume(event);
                items[0]?.focus();
                break;
            case 'End':
                this.#consume(event);
                items[items.length - 1]?.focus();
                break;
            case 'ArrowLeft':
            case 'ArrowRight':
            case 'Escape':
                this.#consume(event);
                this.#setState('closed');
                this.triggerTarget.focus();
                break;
        }
    }

    /** Focus leaving the trigger and its items closes the sub-menu. */
    onFocusout(event: FocusEvent): void {
        const next = event.relatedTarget;
        if (!(next instanceof Node) || !this.element.contains(next)) {
            this.hide();
        }
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

    #open(focusFirst: boolean): void {
        this.#clearTimeouts();
        this.#setState('open');
        if (focusFirst) {
            this.#items()[0]?.focus();
        }
    }

    #items(): HTMLElement[] {
        if (!this.hasContentTarget) {
            return [];
        }

        return [...this.contentTarget.querySelectorAll<HTMLElement>('[role="menuitem"], [role="menuitemradio"]')].filter(
            (item) => !item.hasAttribute('disabled') && 'true' !== item.getAttribute('aria-disabled'),
        );
    }

    #consume(event: KeyboardEvent): void {
        event.preventDefault();
        event.stopPropagation();
    }

    // State lives on the trigger and content directly (not a parent group) so nested
    // sub-menus each react only to their own state.
    #setState(state: State): void {
        this.element.dataset.state = state;
        if (this.hasTriggerTarget) {
            this.triggerTarget.dataset.state = state;
            this.triggerTarget.setAttribute('aria-expanded', String('open' === state));
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
