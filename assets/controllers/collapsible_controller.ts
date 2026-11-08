import { Controller } from '@hotwired/stimulus';

// From the Symfony UX Toolkit shadcn kit (Collapsible), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'content'];

    static values = {
        open: { type: Boolean, default: false },
    };

    declare readonly triggerTargets: HTMLElement[];
    declare readonly contentTargets: HTMLElement[];
    declare openValue: boolean;

    connect(): void {
        this.updateState();
    }

    toggle(): void {
        this.openValue = !this.openValue;
    }

    openValueChanged(): void {
        this.updateState();
    }

    updateState(): void {
        const open = this.openValue;
        const state = open ? 'open' : 'closed';

        this.element.dataset.state = state;

        for (const trigger of this.triggerTargets) {
            trigger.setAttribute('aria-expanded', String(open));
            trigger.dataset.state = state;
        }

        for (const content of this.contentTargets) {
            content.dataset.state = state;
            content.setAttribute('aria-hidden', String(!open));
            content.toggleAttribute('hidden', !open);
        }
    }
}
