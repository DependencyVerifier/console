import { Controller } from '@hotwired/stimulus';

/** Identity token of one closing sequence, so a later open() or disconnect() can cancel it. */
type ClosingToken = object;

// From the Symfony UX Toolkit shadcn kit (Dialog), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'dialog'];

    static values = {
        open: Boolean,
    };

    declare readonly hasTriggerTarget: boolean;
    declare readonly triggerTarget: HTMLElement;
    declare readonly dialogTarget: HTMLDialogElement;
    declare readonly openValue: boolean;

    #wasOpen: boolean | null = null;
    #closing: ClosingToken | null = null;

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

    closeOnClickOutside(event: MouseEvent): void {
        if (event.target === this.dialogTarget) {
            this.close();
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
