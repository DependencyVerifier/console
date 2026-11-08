import { Controller } from '@hotwired/stimulus';

type FocusTarget = 'first' | 'last' | false;

// From the Symfony UX Toolkit shadcn kit (DropdownMenu), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'content', 'item'];

    static values = {
        open: { type: Boolean, default: false },
    };

    declare readonly hasTriggerTarget: boolean;
    declare readonly triggerTarget: HTMLElement;
    declare readonly hasContentTarget: boolean;
    declare readonly contentTarget: HTMLElement;
    declare readonly itemTargets: HTMLElement[];
    declare readonly openValue: boolean;

    readonly #onOutsideClick = (event: MouseEvent): void => {
        if (event.target instanceof Node && !this.element.contains(event.target)) {
            this.close({ focus: false });
        }
    };

    connect(): void {
        this.#setState(this.openValue);
        if (this.openValue) {
            this.open({ focus: false });
        }
    }

    disconnect(): void {
        document.removeEventListener('click', this.#onOutsideClick, true);
    }

    toggle(event?: Event): void {
        event?.preventDefault();
        if ('open' === this.element.dataset.state) {
            this.close();
        } else {
            this.open();
        }
    }

    open({ focus = 'first' }: { focus?: FocusTarget } = {}): void {
        this.#setState(true);
        document.addEventListener('click', this.#onOutsideClick, true);

        if ('last' === focus) {
            this.#focusLastItem();
        } else if (focus) {
            this.#focusFirstItem();
        }
    }

    close({ focus = true }: { focus?: boolean } = {}): void {
        this.#setState(false);
        document.removeEventListener('click', this.#onOutsideClick, true);

        if (focus && this.hasTriggerTarget) {
            this.triggerTarget.focus();
        }
    }

    closeFromItem(event: Event): void {
        const item = this.#itemOf(event);
        if (null === item) {
            return;
        }

        if ('false' !== item.dataset.closeOnSelect) {
            this.close();
        }
    }

    toggleCheckbox(event: Event): void {
        const item = this.#itemOf(event);
        if (null === item) {
            return;
        }

        const checked = 'true' !== item.getAttribute('aria-checked');
        item.setAttribute('aria-checked', String(checked));

        const indicator = item.querySelector<HTMLElement>('[data-checkbox-indicator]');
        if (null !== indicator) {
            indicator.hidden = !checked;
        }

        if ('true' === item.dataset.closeOnSelect) {
            this.close();
        }
    }

    selectRadio(event: Event): void {
        const item = this.#itemOf(event);
        if (null === item) {
            return;
        }

        const group = item.closest<HTMLElement>('[data-slot="dropdown-menu-radio-group"]');
        if (null === group) {
            return;
        }

        const value = item.dataset.value ?? '';
        group.dataset.value = value;

        for (const radio of group.querySelectorAll<HTMLElement>('[role="menuitemradio"]')) {
            const selected = radio.dataset.value === value;
            radio.setAttribute('aria-checked', String(selected));

            const indicator = radio.querySelector<HTMLElement>('[data-radio-indicator]');
            if (null !== indicator) {
                indicator.hidden = !selected;
            }
        }

        if ('true' === item.dataset.closeOnSelect) {
            this.close();
        }
    }

    onTriggerKeydown(event: KeyboardEvent): void {
        if ('ArrowDown' === event.key) {
            event.preventDefault();
            this.open({ focus: 'first' });
        } else if ('ArrowUp' === event.key) {
            event.preventDefault();
            this.open({ focus: 'last' });
        }
    }

    onContentKeydown(event: KeyboardEvent): void {
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.close();
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.#focusNextItem();
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.#focusPrevItem();
                break;
            case 'Home':
                event.preventDefault();
                this.#focusFirstItem();
                break;
            case 'End':
                event.preventDefault();
                this.#focusLastItem();
                break;
            case 'Tab':
                this.close({ focus: false });
                break;
        }
    }

    /** The menu item that received the event, or null (and the default prevented) when it is disabled. */
    #itemOf(event: Event): HTMLElement | null {
        const item = event.currentTarget;
        if (!(item instanceof HTMLElement) || this.#isItemDisabled(item)) {
            event.preventDefault();

            return null;
        }

        return item;
    }

    #setState(isOpen: boolean): void {
        const state = isOpen ? 'open' : 'closed';
        this.element.dataset.state = state;

        if (this.hasTriggerTarget) {
            this.triggerTarget.dataset.state = state;
            this.triggerTarget.setAttribute('aria-expanded', String(isOpen));
        }
        if (this.hasContentTarget) {
            this.contentTarget.dataset.state = state;
        }
    }

    #isItemDisabled(item: HTMLElement): boolean {
        return 'true' === item.getAttribute('aria-disabled') || 'true' === item.dataset.disabled || item.hasAttribute('disabled');
    }

    #enabledItems(): HTMLElement[] {
        // Exclude items nested in a sub-menu: they are not part of this menu's roving focus.
        return this.itemTargets.filter(
            (item) => !this.#isItemDisabled(item) && null === item.closest('[data-slot="dropdown-menu-sub-content"]'),
        );
    }

    #focusFirstItem(): void {
        this.#enabledItems()[0]?.focus();
    }

    #focusLastItem(): void {
        this.#enabledItems().at(-1)?.focus();
    }

    #focusNextItem(): void {
        const items = this.#enabledItems();
        if (0 === items.length) {
            return;
        }
        const index = items.indexOf(document.activeElement as HTMLElement);
        items[-1 === index ? 0 : (index + 1) % items.length]?.focus();
    }

    #focusPrevItem(): void {
        const items = this.#enabledItems();
        if (0 === items.length) {
            return;
        }
        const index = items.indexOf(document.activeElement as HTMLElement);
        items[-1 === index ? items.length - 1 : (index - 1 + items.length) % items.length]?.focus();
    }
}
