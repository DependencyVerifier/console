import { Controller } from '@hotwired/stimulus';

/** Identity token of one closing sequence, so a later open() or disconnect() can cancel it. */
type ClosingToken = object;

/*
 * From the Symfony UX Toolkit shadcn kit (Dialog), converted to TypeScript.
 *
 * Every native close request (Esc, the Android back gesture) arrives as `cancel` and goes through the same animated
 * close(); a close the controller did not start (`close` event) still resets the trigger's aria-expanded. A click on
 * the backdrop closes the dialog only when the pointer went down and up outside its box: the <dialog> is also the
 * target of its own padding and of the gaps between its children, and a text selection may end outside it.
 * `lightDismiss: false` (form dialogs) never closes on the backdrop, so typed values are not lost by a stray click.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'dialog'];

    static values = {
        open: Boolean,
        lightDismiss: { type: Boolean, default: true },
    };

    declare readonly hasTriggerTarget: boolean;
    declare readonly triggerTarget: HTMLElement;
    declare readonly dialogTarget: HTMLDialogElement;
    declare readonly openValue: boolean;
    declare readonly lightDismissValue: boolean;

    #wasOpen: boolean | null = null;
    #closing: ClosingToken | null = null;
    #pressedOutside = false;

    connect(): void {
        if (this.#wasOpen ?? this.openValue) {
            this.open();
        }
    }

    disconnect(): void {
        // A <dialog> taken out of the DOM comes back open but no longer modal, so reopen it on reconnect.
        this.#wasOpen = this.dialogTarget.open && !this.dialogTarget.hasAttribute('data-closing');
        this.#closing = null;
        this.dialogTarget.removeAttribute('data-closing');
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    open(): void {
        this.#closing = null;
        // Reopened before the exit transition ended: transition back instead.
        const reopened = this.dialogTarget.open && this.dialogTarget.hasAttribute('data-closing');
        this.dialogTarget.removeAttribute('data-closing');
        if (!reopened) {
            this.dialogTarget.showModal();
        }
        this.#focusInitialElement();

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'true');
        }
    }

    pointerDown(event: PointerEvent): void {
        this.#pressedOutside = event.target === this.dialogTarget && this.#outside(event);
    }

    closeOnClickOutside(event: MouseEvent): void {
        const pressedOutside = this.#pressedOutside;
        this.#pressedOutside = false;
        if (this.lightDismissValue && pressedOutside && event.target === this.dialogTarget && this.#outside(event)) {
            this.close();
        }
    }

    /** Esc or another platform close request: animate it like any other close. */
    cancel(event: Event): void {
        if (event.cancelable) {
            event.preventDefault();
            this.close();
        }
    }

    /** The dialog closed, whoever closed it. */
    closed(): void {
        this.#closing = null;
        this.dialogTarget.removeAttribute('data-closing');
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }
    }

    close(): void {
        const dialog = this.dialogTarget;
        if (!dialog.open || dialog.hasAttribute('data-closing')) {
            return;
        }

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }

        // Only Chromium can transition a <dialog> out of the top layer, so play the exit
        // transition while it is still open, through [data-closing], then close it.
        dialog.setAttribute('data-closing', '');
        const closing: ClosingToken = {};
        this.#closing = closing;
        const finish = (): void => this.#finishClosing(dialog, closing);
        // The <dialog> and its ::backdrop only: an endless animation in the content would never let it close.
        const endTimes = dialog
            .getAnimations({ subtree: true })
            .filter((animation) => {
                const effect = animation.effect;

                return effect instanceof KeyframeEffect && effect.target === dialog && Number.isFinite(Number(effect.getComputedTiming().endTime));
            })
            .map((animation) => ({ animation, endTime: Number(animation.effect?.getComputedTiming().endTime ?? 0) }));

        if (0 === endTimes.length) {
            finish();

            return;
        }

        void Promise.allSettled(endTimes.map(({ animation }) => animation.finished)).then(finish);
        // A paused animation never finishes: close anyway once the longest one should have ended.
        setTimeout(finish, Math.max(...endTimes.map(({ endTime }) => endTime)) + 100);
    }

    #focusInitialElement(): void {
        // showModal() already focuses an [autofocus] target or the first focusable element;
        // when the author did not opt into autofocus, prefer the first form field instead.
        if (null !== this.dialogTarget.querySelector('[autofocus]')) {
            return;
        }

        this.dialogTarget
            .querySelector<HTMLElement>('input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])')
            ?.focus();
    }

    #outside(event: MouseEvent): boolean {
        const box = this.dialogTarget.getBoundingClientRect();

        return event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
    }

    #finishClosing(dialog: HTMLDialogElement, closing: ClosingToken): void {
        // open() or disconnect() ran in the meantime and cancelled this closing.
        if (this.#closing !== closing) {
            return;
        }

        this.#closing = null;
        dialog.removeAttribute('data-closing');
        dialog.close();
    }
}
