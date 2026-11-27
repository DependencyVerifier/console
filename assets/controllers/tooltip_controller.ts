import { Controller } from '@hotwired/stimulus';

const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])';
/** Time to move the pointer from the trigger onto the tooltip before it hides (WCAG 1.4.13, hoverable). */
const HIDE_DELAY = 150;

/**
 * <twig:Tooltip>: shown on hover and on focus, dismissed with Esc, hoverable, kept while hovered or focused
 * (WCAG 1.4.13; interest-triggered-tooltips). Three levels, each one a fallback of the previous (Browser Support in
 * CLAUDE.md):
 * - `interestfor` on the trigger and `popover="hint"` (else `manual`): the browser shows, hides and dismisses it;
 * - Popover API with anchor positioning: this controller shows the popover in the top layer, app.css tethers it;
 * - neither: the tooltip stays a box positioned under the wrapper (it may be clipped by a scrolling ancestor).
 * Anchor names go through the CSSOM: a `style` attribute would break the CSP.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['content'];

    static values = { describes: { type: Boolean, default: true } };

    declare readonly contentTarget: HTMLElement;
    declare readonly describesValue: boolean;

    #trigger: HTMLElement | null = null;
    /** 'interest': the browser handles it; 'popover': top layer, shown here; 'box': no popover at all. */
    #mode: 'interest' | 'popover' | 'box' = 'box';
    #hideTimeout: ReturnType<typeof setTimeout> | null = null;

    readonly #onKeydown = (event: KeyboardEvent): void => {
        if ('Escape' === event.key && this.#shown) {
            // Dismisses the tooltip only: an enclosing dialog stays open.
            event.preventDefault();
            event.stopPropagation();
            this.#hideNow();
        }
    };

    connect(): void {
        const content = this.contentTarget;
        this.#trigger = [...this.element.querySelectorAll<HTMLElement>(FOCUSABLE)].find((element) => !content.contains(element)) ?? null;
        if (null === this.#trigger) {
            return;
        }
        if (this.describesValue) {
            this.#trigger.setAttribute('aria-describedby', [this.#trigger.getAttribute('aria-describedby'), content.id].filter(Boolean).join(' '));
        }

        const popover = Object.hasOwn(HTMLElement.prototype, 'popover') && CSS.supports('position-area', 'block-start');
        if (!popover) {
            return;
        }
        const anchor = `--${content.id}`;
        this.#trigger.style.setProperty('anchor-name', anchor);
        content.style.setProperty('position-anchor', anchor);
        content.removeAttribute('hidden');
        content.popover = 'hint';
        if ('hint' !== content.popover) {
            content.popover = 'manual';
        }
        const interest = 'interestForElement' in HTMLButtonElement.prototype && (this.#trigger instanceof HTMLButtonElement || this.#trigger instanceof HTMLAnchorElement);
        if (interest) {
            this.#trigger.setAttribute('interestfor', content.id);
        }
        this.#mode = interest ? 'interest' : 'popover';
    }

    disconnect(): void {
        this.#hideNow();
    }

    show(): void {
        if ('interest' === this.#mode || null === this.#trigger) {
            return;
        }
        this.#clearHide();
        if ('popover' === this.#mode) {
            if (!this.contentTarget.matches(':popover-open')) {
                this.contentTarget.showPopover();
            }
        } else {
            this.contentTarget.hidden = false;
        }
        document.addEventListener('keydown', this.#onKeydown, true);
    }

    hide(event?: Event): void {
        if ('interest' === this.#mode) {
            return;
        }
        // Still hovered or focused somewhere inside: keep it.
        if (event instanceof FocusEvent && event.relatedTarget instanceof Node && this.element.contains(event.relatedTarget)) {
            return;
        }
        this.#clearHide();
        this.#hideTimeout = setTimeout(() => {
            if (!this.element.matches(':hover') && !this.element.contains(document.activeElement)) {
                this.#hideNow();
            }
        }, HIDE_DELAY);
    }

    get #shown(): boolean {
        return 'popover' === this.#mode ? this.contentTarget.matches(':popover-open') : !this.contentTarget.hidden;
    }

    #hideNow(): void {
        this.#clearHide();
        document.removeEventListener('keydown', this.#onKeydown, true);
        if ('popover' === this.#mode) {
            if (this.contentTarget.matches(':popover-open')) {
                this.contentTarget.hidePopover();
            }
        } else if ('box' === this.#mode) {
            this.contentTarget.hidden = true;
        }
    }

    #clearHide(): void {
        if (null !== this.#hideTimeout) {
            clearTimeout(this.#hideTimeout);
            this.#hideTimeout = null;
        }
    }
}
