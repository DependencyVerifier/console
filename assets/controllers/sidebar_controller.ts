import { Controller } from '@hotwired/stimulus';

type State = 'expanded' | 'collapsed';

const STATE_EXPANDED: State = 'expanded';
const STATE_COLLAPSED: State = 'collapsed';

/**
 * Expand/collapse behavior for the `Sidebar` component: cookie persistence,
 * an off-canvas mode on mobile and a keyboard shortcut.
 * From the Symfony UX Toolkit shadcn kit, converted to TypeScript.
 *
 * Below the breakpoint the sidebar content is relocated into the `Sheet`
 * dialog (which brings the backdrop, slide-in, Escape and click-outside for
 * free) and moved back into the desktop layout above it.
 *
 * @value defaultOpen Initial open state when no cookie is present.
 * @value cookieName Cookie used to persist the sidebar state across reloads (empty disables persistence).
 * @value cookieMaxAge Cookie max-age, in seconds.
 * @value mobileBreakpoint Pixel width below which the sidebar behaves as an off-canvas sheet.
 * @value keyboardShortcut Single key combined with Ctrl/Cmd that toggles the sidebar (empty disables the shortcut).
 * @action toggle Toggle the sidebar between expanded and collapsed (or open/close the mobile sheet).
 * @action open Expand the sidebar (or open the mobile sheet).
 * @action close Collapse the sidebar (or close the mobile sheet).
 * @action handleKeydown Toggle the sidebar on the keyboard shortcut.
 */
export default class extends Controller<HTMLElement> {
    static values = {
        defaultOpen: { type: Boolean, default: true },
        cookieName: { type: String, default: 'sidebar:state' },
        cookieMaxAge: { type: Number, default: 60 * 60 * 24 * 7 },
        mobileBreakpoint: { type: Number, default: 768 },
        keyboardShortcut: { type: String, default: 'b' },
    };

    declare readonly defaultOpenValue: boolean;
    declare readonly cookieNameValue: string;
    declare readonly cookieMaxAgeValue: number;
    declare readonly mobileBreakpointValue: number;
    declare readonly keyboardShortcutValue: string;

    #mobileQuery: MediaQueryList | null = null;
    #shortcut = '';
    #sidebar: HTMLElement | null = null;
    #desktopSlot: HTMLElement | null = null;
    #mobileSlot: HTMLElement | null = null;
    #mobileDialog: HTMLDialogElement | null = null;

    readonly #onMediaChange = (): void => {
        this.#syncPortal();
        this.#render();
    };

    readonly #onDialogClose = (): void => {
        this.#render();
    };

    connect(): void {
        this.#mobileQuery = window.matchMedia(`(max-width: ${this.mobileBreakpointValue - 1}px)`);
        this.#shortcut = this.keyboardShortcutValue.toLowerCase();
        this.#sidebar = this.element.querySelector<HTMLElement>('[data-slot="sidebar"]');
        this.#desktopSlot = this.element.querySelector<HTMLElement>('[data-slot="sidebar-inner"]');
        this.#mobileSlot = this.element.querySelector<HTMLElement>('[data-slot="sidebar-mobile-inner"]');
        this.#mobileDialog = this.#mobileSlot?.closest('dialog') ?? null;

        const cookieValue = this.#readCookie();
        if (STATE_EXPANDED === cookieValue || STATE_COLLAPSED === cookieValue) {
            this.element.dataset.state = cookieValue;
        } else {
            this.element.dataset.state = this.defaultOpenValue ? STATE_EXPANDED : STATE_COLLAPSED;
        }

        this.#syncPortal();
        this.#mobileQuery.addEventListener('change', this.#onMediaChange);
        this.#mobileDialog?.addEventListener('close', this.#onDialogClose);
        this.#render();
    }

    disconnect(): void {
        this.#mobileQuery?.removeEventListener('change', this.#onMediaChange);
        this.#mobileDialog?.removeEventListener('close', this.#onDialogClose);
    }

    toggle(event?: Event): void {
        event?.preventDefault();
        if (this.#isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open(): void {
        if (this.#isMobile()) {
            this.#mobileDialog?.showModal();
        } else {
            this.#setState(STATE_EXPANDED);
        }
        this.#render();
    }

    close(): void {
        if (this.#isMobile()) {
            this.#mobileDialog?.close();
        } else {
            this.#setState(STATE_COLLAPSED);
        }
        this.#render();
    }

    handleKeydown(event: KeyboardEvent): void {
        if ('' === this.#shortcut || event.key.toLowerCase() !== this.#shortcut) {
            return;
        }
        if (!event.ctrlKey && !event.metaKey) {
            return;
        }
        const target = event.target;
        if (target instanceof HTMLElement && ('INPUT' === target.tagName || 'TEXTAREA' === target.tagName || target.isContentEditable)) {
            return;
        }
        event.preventDefault();
        this.toggle();
    }

    #isExpanded(): boolean {
        return STATE_EXPANDED === this.element.dataset.state;
    }

    #isOpen(): boolean {
        return this.#isMobile() ? (this.#mobileDialog?.open ?? false) : this.#isExpanded();
    }

    #setState(state: State): void {
        if (this.element.dataset.state === state) {
            return;
        }
        this.element.dataset.state = state;
        this.#writeCookie(state);
    }

    #isMobile(): boolean {
        return this.#mobileQuery?.matches ?? false;
    }

    #syncPortal(): void {
        if (null === this.#desktopSlot || null === this.#mobileSlot) {
            return;
        }
        if (this.#isMobile()) {
            this.#moveChildren(this.#desktopSlot, this.#mobileSlot);
        } else {
            if (this.#mobileDialog?.open) {
                this.#mobileDialog.close();
            }
            this.#moveChildren(this.#mobileSlot, this.#desktopSlot);
        }
    }

    #moveChildren(from: HTMLElement, to: HTMLElement): void {
        while (null !== from.firstChild) {
            to.appendChild(from.firstChild);
        }
    }

    #render(): void {
        const state = this.element.dataset.state ?? STATE_EXPANDED;

        if (null !== this.#sidebar) {
            this.#sidebar.dataset.state = state;

            // Mirror shadcn React: data-collapsible is the active mode only when collapsed.
            const mode = this.#sidebar.dataset.collapsibleMode || 'icon';
            this.#sidebar.dataset.collapsible = STATE_COLLAPSED === state ? mode : '';
        }

        if ('' !== this.element.id) {
            const expanded = this.#isOpen();
            for (const trigger of document.querySelectorAll(`[data-sidebar-target="${this.element.id}"]`)) {
                trigger.setAttribute('aria-expanded', String(expanded));
            }
        }
    }

    #readCookie(): string | null {
        if ('' === this.cookieNameValue) {
            return null;
        }
        const prefix = `${this.cookieNameValue}=`;
        for (const part of document.cookie.split('; ')) {
            if (part.startsWith(prefix)) {
                return decodeURIComponent(part.substring(prefix.length));
            }
        }

        return null;
    }

    #writeCookie(value: State): void {
        if ('' === this.cookieNameValue) {
            return;
        }
        document.cookie = `${this.cookieNameValue}=${encodeURIComponent(value)}; path=/; max-age=${this.cookieMaxAgeValue}`;
    }
}
