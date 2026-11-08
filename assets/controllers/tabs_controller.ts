import { Controller } from '@hotwired/stimulus';

// From the Symfony UX Toolkit shadcn kit (Tabs), converted to TypeScript.
export default class extends Controller<HTMLElement> {
    static targets = ['trigger', 'tab'];
    static values = { activeTab: String };

    declare readonly triggerTargets: HTMLElement[];
    declare readonly tabTargets: HTMLElement[];
    declare activeTabValue: string;

    open(event: Event): void {
        const trigger = event.currentTarget;
        if (trigger instanceof HTMLElement && undefined !== trigger.dataset.tabId) {
            this.activeTabValue = trigger.dataset.tabId;
        }
    }

    activeTabValueChanged(): void {
        for (const trigger of this.triggerTargets) {
            const isActive = trigger.dataset.tabId === this.activeTabValue;
            trigger.toggleAttribute('data-active', isActive);
            trigger.setAttribute('aria-selected', String(isActive));
        }

        for (const tab of this.tabTargets) {
            const isActive = tab.dataset.tabId === this.activeTabValue;
            tab.toggleAttribute('data-active', isActive);
            tab.dataset.state = isActive ? 'active' : 'inactive';
        }
    }
}
