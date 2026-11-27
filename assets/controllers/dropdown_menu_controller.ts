import { Controller } from '@hotwired/stimulus';

type FocusTarget = 'first' | 'last' | false;

/*
 * From the Symfony UX Toolkit shadcn kit (DropdownMenu), converted to TypeScript.
 *
 * Progressive enhancement (menu, viewport-aware-dropdowns): where both the Popover API and CSS anchor positioning
 * exist, the content becomes a `popover="auto"` invoked by its trigger button. It then sits in the top layer (no
 * ancestor, sheet or table clips it), flips when the viewport is short (app.css), and the browser light-dismisses it.
 * Elsewhere it stays the kit's absolutely positioned box, closed by the document click listener.
 * A pointer click leaves the focus on the trigger; the keyboard (Enter, Space, arrows) moves it into the menu.
 */
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

    /** The content is a native popover (see the class comment). */
    #native = false;
    /** Where the focus goes once the native popover has opened from a click on its trigger. */
    #focusOnToggle: FocusTarget = false;

    readonly #onOutsideClick = (event: MouseEvent): void => {
        if (event.target instanceof Node && !this.element.contains(event.target)) {
            this.close({ focus: false });
        }
    };

    readonly #onToggle = (event: Event): void => {
        const open = 'open' === (event as ToggleEvent).newState;
        this.#setState(open);
        if (open) {
            this.#focus(this.#focusOnToggle);
        }
        this.#focusOnToggle = false;
    };

    connect(): void {
        this.#native = this.hasTriggerTarget
            && this.hasContentTarget
            && this.triggerTarget instanceof HTMLButtonElement
            && Object.hasOwn(HTMLElement.prototype, 'popover')
            && CSS.supports('position-area', 'block-end');
        if (this.#native && this.triggerTarget instanceof HTMLButtonElement) {
            // Anchor names through the CSSOM: a `style` attribute would break the CSP, a class cannot carry an id.
            const anchor = `--${this.contentTarget.id || 'dropdown-menu'}`;
            this.triggerTarget.style.setProperty('anchor-name', anchor);
            this.contentTarget.style.setProperty('position-anchor', anchor);
            this.contentTarget.popover = 'auto';
            this.triggerTarget.popoverTargetElement = this.contentTarget;
            this.contentTarget.addEventListener('toggle', this.#onToggle);
        }

        this.#setState(this.openValue);
        if (this.openValue) {
            this.open({ focus: false });
        }
    }

    disconnect(): void {
        document.removeEventListener('click', this.#onOutsideClick, true);
        if (this.#native && this.hasContentTarget) {
            this.contentTarget.removeEventListener('toggle', this.#onToggle);
        }
    }

    toggle(event?: Event): void {
        // A click from the keyboard (Enter, Space) has no pointer details: the focus goes into the menu.
        const fromKeyboard = event instanceof MouseEvent && 0 === event.detail;
        if (this.#native) {
            // The trigger's popovertarget opens or closes the popover after this listener: never prevent it.
            this.#focusOnToggle = fromKeyboard ? 'first' : false;

            return;
        }

        event?.preventDefault();
        if ('open' === this.element.dataset.state) {
            this.close();
        } else {
            this.open({ focus: fromKeyboard ? 'first' : false });
        }
    }

    open({ focus = 'first' }: { focus?: FocusTarget } = {}): void {
        this.#setState(true);
        if (this.#native) {
            if (!this.contentTarget.matches(':popover-open')) {
                this.contentTarget.showPopover();
            }
        } else {
            document.addEventListener('click', this.#onOutsideClick, true);
        }

        this.#focus(focus);
    }

    close({ focus = true }: { focus?: boolean } = {}): void {
        this.#setState(false);
        if (this.#native) {
            if (this.contentTarget.matches(':popover-open')) {
                this.contentTarget.hidePopover();
            }
        } else {
            document.removeEventListener('click', this.#onOutsideClick, true);
        }

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
                // Handled here only: a dialog or a sheet around the menu must stay open.
                event.preventDefault();
                event.stopPropagation();
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

    #focus(target: FocusTarget): void {
        if ('last' === target) {
            this.#focusLastItem();
        } else if (target) {
            this.#focusCurrentOrFirstItem();
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

    /** On opening, the current entry (`aria-current="page"`, e.g. the browsed team) rather than the first one. */
    #focusCurrentOrFirstItem(): void {
        const items = this.#enabledItems();
        (items.find((item) => 'page' === item.getAttribute('aria-current')) ?? items[0])?.focus();
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
