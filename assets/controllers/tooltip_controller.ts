/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

type Side = 'top' | 'right' | 'bottom' | 'left';

const SIDES: readonly Side[] = ['top', 'right', 'bottom', 'left'];

// From the Symfony UX Toolkit shadcn kit (Tooltip), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static values = {
        delayDuration: Number,
        // Using targets does not work if the elements are moved in the DOM (document.body.appendChild)
        // and using outlets does not work either if elements are children of the controller element.
        wrapperSelector: String,
        contentSelector: String,
        arrowSelector: String,
    };
    static targets = ['trigger', 'wrapper'];

    declare readonly hasDelayDurationValue: boolean;
    declare readonly delayDurationValue: number;
    declare readonly wrapperSelectorValue: string;
    declare readonly contentSelectorValue: string;
    declare readonly arrowSelectorValue: string;
    declare readonly triggerTarget: HTMLElement;

    #elements: { wrapper: HTMLElement; content: HTMLElement; arrow: HTMLElement } | null = null;
    #side: Side = 'top';
    #sideOffset = 0;
    #showTimeout: ReturnType<typeof setTimeout> | null = null;

    readonly #dismiss = (): void => {
        this.hide();
    };

    connect(): void {
        this.#elements = null;
        const wrapper = document.querySelector<HTMLElement>(this.wrapperSelectorValue);
        const content = document.querySelector<HTMLElement>(this.contentSelectorValue);
        const arrow = document.querySelector<HTMLElement>(this.arrowSelectorValue);

        if (null === wrapper || null === content || null === arrow) {
            return;
        }

        const side = wrapper.getAttribute('data-side');
        this.#side = SIDES.find((candidate) => candidate === side) ?? 'top';
        this.#sideOffset = Number.parseInt(wrapper.getAttribute('data-side-offset') ?? '', 10) || 0;

        document.body.appendChild(wrapper);
        this.#elements = { wrapper, content, arrow };
    }

    disconnect(): void {
        this.#clearTimeout();
        this.#removeDismissListeners();

        const wrapper = this.#elements?.wrapper;
        if (undefined !== wrapper && wrapper.parentNode === document.body) {
            this.element.appendChild(wrapper);
        }
    }

    wrapperTargetConnected(element: HTMLElement): void {
        // This case appears when a Live Component re-renders.
        // Because the original wrapper is moved to <body>, the smart re-render algorithm recreates a new wrapper.
        // The same wrapper comes back when the controller reconnects, as disconnect() moves it back.
        const wrapper = this.#elements?.wrapper;
        if (undefined !== wrapper && element !== wrapper) {
            wrapper.remove();
            this.connect();
        }
    }

    show(): void {
        const elements = this.#elements;
        if (null === elements) {
            return;
        }

        this.#clearTimeout();

        const delay = this.hasDelayDurationValue ? this.delayDurationValue : 0;

        this.#showTimeout = setTimeout(() => {
            elements.wrapper.setAttribute('open', '');
            elements.content.setAttribute('open', '');
            elements.arrow.setAttribute('open', '');
            this.#fitWidthToText(elements.content, elements.arrow);
            this.#positionElements(elements.wrapper, elements.content, elements.arrow);
            // The tooltip is portaled to <body> and positioned absolutely, so it cannot follow
            // the trigger on scroll. Dismiss it instead (capture scrolls from any scroller).
            window.addEventListener('scroll', this.#dismiss, true);
            window.addEventListener('resize', this.#dismiss);
            this.#showTimeout = null;
        }, delay);
    }

    hide(): void {
        const elements = this.#elements;
        if (null === elements) {
            return;
        }

        this.#clearTimeout();
        this.#removeDismissListeners();
        elements.wrapper.removeAttribute('open');
        elements.content.removeAttribute('open');
        elements.arrow.removeAttribute('open');
    }

    // A shrink-to-fit box that wraps its text keeps its max width, leaving a gap after the longest line.
    // Size the content to that line instead.
    #fitWidthToText(content: HTMLElement, arrow: HTMLElement): void {
        content.style.width = '';
        const range = document.createRange();
        let left = Infinity;
        let right = -Infinity;
        for (const node of content.childNodes) {
            if (node === arrow) {
                continue;
            }
            range.selectNodeContents(node);
            for (const rect of range.getClientRects()) {
                left = Math.min(left, rect.left);
                right = Math.max(right, rect.right);
            }
        }
        if (right <= left) {
            return;
        }
        // The rects are shrunk by the opening animation (scale-95), scale them back to the layout size.
        const scale = content.getBoundingClientRect().width / content.offsetWidth;
        const style = getComputedStyle(content);
        const padding = Number.parseFloat(style.paddingLeft) + Number.parseFloat(style.paddingRight);
        // The extra pixel keeps a sub-pixel rounding from wrapping the last word.
        content.style.width = `${Math.ceil((right - left) / scale + padding) + 1}px`;
    }

    #removeDismissListeners(): void {
        window.removeEventListener('scroll', this.#dismiss, true);
        window.removeEventListener('resize', this.#dismiss);
    }

    #clearTimeout(): void {
        if (null !== this.#showTimeout) {
            clearTimeout(this.#showTimeout);
            this.#showTimeout = null;
        }
    }

    #positionElements(wrapper: HTMLElement, content: HTMLElement, arrow: HTMLElement): void {
        const triggerRect = this.triggerTarget.getBoundingClientRect();
        // Unlike getBoundingClientRect(), offsetWidth/offsetHeight ignore the opening animation (scale-95).
        const contentRect = { width: content.offsetWidth, height: content.offsetHeight };
        const arrowRect = arrow.getBoundingClientRect();

        let wrapperLeft = 0;
        let wrapperTop = 0;
        let arrowLeft: number | null = null;
        let arrowTop: number | null = null;
        switch (this.#side) {
            case 'left':
                wrapperLeft = triggerRect.left - contentRect.width - arrowRect.width / 2 - this.#sideOffset;
                wrapperTop = triggerRect.top - contentRect.height / 2 + triggerRect.height / 2;
                arrowTop = contentRect.height / 2 - arrowRect.height / 2;
                break;
            case 'top':
                wrapperLeft = triggerRect.left - contentRect.width / 2 + triggerRect.width / 2;
                wrapperTop = triggerRect.top - contentRect.height - arrowRect.height / 2 - this.#sideOffset;
                arrowLeft = contentRect.width / 2 - arrowRect.width / 2;
                break;
            case 'right':
                wrapperLeft = triggerRect.right + arrowRect.width / 2 + this.#sideOffset;
                wrapperTop = triggerRect.top - contentRect.height / 2 + triggerRect.height / 2;
                arrowTop = contentRect.height / 2 - arrowRect.height / 2;
                break;
            case 'bottom':
                wrapperLeft = triggerRect.left - contentRect.width / 2 + triggerRect.width / 2;
                wrapperTop = triggerRect.bottom + arrowRect.height / 2 + this.#sideOffset;
                arrowLeft = contentRect.width / 2 - arrowRect.width / 2;
                break;
        }

        // Keep a top or bottom tooltip inside the viewport, and move the arrow so it still points at the trigger.
        if (null !== arrowLeft) {
            const margin = 16;
            const maxLeft = document.documentElement.clientWidth - contentRect.width - margin;
            const clampedLeft = Math.max(margin, Math.min(wrapperLeft, maxLeft));
            arrowLeft += wrapperLeft - clampedLeft;
            wrapperLeft = clampedLeft;
        }

        wrapper.style.transform = `translate3d(${wrapperLeft}px, ${wrapperTop}px, 0)`;
        if (null !== arrowLeft) {
            arrow.style.left = `${arrowLeft}px`;
        }
        if (null !== arrowTop) {
            arrow.style.top = `${arrowTop}px`;
        }
    }
}
